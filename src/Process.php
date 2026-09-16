<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Shell-free, bounded Linux subprocess execution. Output is never logged here. */
final class Process
{
    public function run(array $argv, string $input = '', int $timeout = 60, array $environment = []): array
    {
        Validate::argv($argv);
        Validate::integer($timeout, 1, 86400);
        if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
            throw new Fault('missing_process_support', 'Linux setsid and the PHP posix extension are required.');
        }
        $env = ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C.UTF-8', 'HOME' => '/nonexistent'];
        foreach ($environment as $key => $value) {
            if (!in_array($key, ['INCUS_CONF', 'HOME', 'PGPASSFILE'], true) || !is_string($value)) {
                throw new Fault('invalid_environment', 'Unsupported subprocess environment.');
            }
            $env[$key] = $value;
        }
        $pipes = [];
        $process = proc_open(['/usr/bin/setsid', '--wait', ...$argv],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new Fault('process_start_failed', 'Unable to start subprocess.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $pid = proc_get_status($process)['pid'];
        $started = hrtime(true);
        $out = ['', ''];
        $offset = 0;
        $status = null;
        try {
            while (true) {
                if (isset($pipes[0])) {
                    if ($offset < strlen($input)) {
                        $written = @fwrite($pipes[0], substr($input, $offset, 65536));
                        if ($written === false) {
                            fclose($pipes[0]);
                            unset($pipes[0]);
                        } else {
                            $offset += $written;
                        }
                    } else {
                        fclose($pipes[0]);
                        unset($pipes[0]);
                    }
                }
                foreach ([1, 2] as $fd) {
                    $chunk = stream_get_contents($pipes[$fd], 65536);
                    if ($chunk !== false) {
                        $out[$fd - 1] .= $chunk;
                    }
                }
                if (strlen($out[0]) + strlen($out[1]) > 1024 * 1024) {
                    throw new Fault('output_limit', 'Subprocess output exceeded its limit.');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    foreach ([1, 2] as $fd) {
                        $tail = stream_get_contents($pipes[$fd], 1024 * 1024 + 1);
                        $out[$fd - 1] .= $tail === false ? '' : $tail;
                    }
                    if (strlen($out[0]) + strlen($out[1]) > 1024 * 1024) {
                        throw new Fault('output_limit', 'Subprocess output exceeded its limit.');
                    }
                    break;
                }
                if ((hrtime(true) - $started) / 1e9 >= $timeout) {
                    throw new Fault('process_timeout', 'Subprocess timed out; inspect the remote operation before retrying.');
                }
                usleep(10000);
            }
        } finally {
            // Also terminate descendants that outlived their parent, including on output-limit errors.
            @posix_kill(-$pid, 15);
            usleep(10000);
            @posix_kill(-$pid, 9);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $closed = proc_close($process);
        }
        return ['exit' => $status['exitcode'] >= 0 ? $status['exitcode'] : $closed,
            'stdout' => $out[0], 'stderr' => $out[1]];
    }

    public function requireSuccess(array $argv, string $input = '', int $timeout = 60, array $environment = []): string
    {
        $result = $this->run($argv, $input, $timeout, $environment);
        if ($result['exit'] !== 0) {
            throw new Fault('command_failed', 'A subprocess failed; its private output was not exposed.');
        }
        return $result['stdout'];
    }
}

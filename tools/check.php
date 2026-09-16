<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__);
$process = new JoomEngine\Workspace\Process();
$failed = false;
$checked = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if (!$file->isFile() || preg_match('~^(?:\.git|vendor|var|build|artifacts)/~', $relative)) {
        continue;
    }
    if ($file->getExtension() === 'php' || $relative === 'bin/workspace') {
        $result = $process->run([PHP_BINARY, '-l', $file->getPathname()]);
        ++$checked;
        if ($result['exit'] !== 0) {
            fwrite(STDERR, 'Syntax failure: ' . $relative . "\n");
            $failed = true;
        }
    }
    if ($file->getExtension() === 'json') {
        try {
            json_decode(file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            fwrite(STDERR, 'JSON failure: ' . $relative . "\n");
            $failed = true;
        }
    }
}
echo "Checked {$checked} PHP files.\n";
exit($failed ? 1 : 0);

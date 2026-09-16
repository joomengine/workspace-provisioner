<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Authenticated bounded-memory backup encryption; never extracts or executes an archive. */
final readonly class Archive
{
    private const MAGIC = "JCBWP01\n";
    private const CHUNK = 1048576;
    private string $key;

    public function __construct(string $keyFile, string $installation)
    {
        $hex = trim(Files::readPrivate($keyFile, 128));
        Validate::digest($hex);
        $this->key = hash_hkdf('sha256', sodium_hex2bin($hex), 32, 'workspace-backups-v1', Validate::uuid($installation));
    }

    public function seal(string $input, string $output, string $context): void
    {
        $this->transform($input, $output, function ($in, $out) use ($context): void {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            self::writeAll($out, self::MAGIC . $header);
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) { throw new Fault('archive_read_failed', 'Unable to read backup stream.'); }
                if ($chunk === '') { continue; }
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, $context);
                self::writeAll($out, pack('N', strlen($cipher)) . $cipher);
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', $context,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            self::writeAll($out, pack('N', strlen($final)) . $final);
        });
    }

    public function open(string $input, string $output, string $context): void
    {
        $this->transform($input, $output, function ($in, $out) use ($context): void {
            if (self::readExact($in, strlen(self::MAGIC)) !== self::MAGIC) { throw new Fault('invalid_archive', 'Unknown archive format.'); }
            $header = self::readExact($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            while (true) {
                $length = unpack('Nlength', self::readExact($in, 4))['length'];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $length > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new Fault('invalid_archive', 'Invalid encrypted frame size.');
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, self::readExact($in, $length), $context);
                if ($result === false) { throw new Fault('invalid_archive', 'Backup authentication failed.'); }
                [$plain, $tag] = $result;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if ($plain !== '' || fread($in, 1) !== '') { throw new Fault('invalid_archive', 'Invalid archive terminator or trailing data.'); }
                    break;
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) { throw new Fault('invalid_archive', 'Unsupported frame tag.'); }
                self::writeAll($out, $plain);
            }
        });
    }

    private function transform(string $input, string $output, callable $body): void
    {
        Files::protectedPath($input);
        Files::protectedPath($output);
        $stat = @lstat($input);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
            || !in_array($stat['uid'], [0, posix_geteuid()], true) || file_exists($output)) {
            throw new Fault('unsafe_archive', 'Use private regular input and a new private output path.');
        }
        $temporary = dirname($output) . '/.archive-' . bin2hex(random_bytes(16));
        $in = fopen($input, 'rb');
        $old = umask(0077);
        $out = fopen($temporary, 'xb');
        umask($old);
        if ($in === false || $out === false) {
            if (is_resource($in)) { fclose($in); }
            if (is_resource($out)) { fclose($out); }
            if (is_file($temporary)) { unlink($temporary); }
            throw new Fault('archive_open_failed', 'Unable to open archive streams.');
        }
        try {
            $body($in, $out);
            if (!fflush($out) || !fsync($out) || !rename($temporary, $output)) {
                throw new Fault('archive_write_failed', 'Unable to persist archive.');
            }
        } finally {
            fclose($in);
            fclose($out);
            if (is_file($temporary)) { unlink($temporary); }
        }
    }

    private static function readExact($stream, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($stream, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') { throw new Fault('invalid_archive', 'Truncated encrypted archive.'); }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    private static function writeAll($stream, string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) { throw new Fault('archive_write_failed', 'Unable to write archive stream.'); }
            $offset += $written;
        }
    }
}

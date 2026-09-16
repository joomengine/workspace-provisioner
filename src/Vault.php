<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Workspace-only credentials, encrypted at rest; never used for platform tokens. */
final class Vault
{
    private readonly string $key;

    public function __construct(private readonly string $directory, string $keyFile)
    {
        Files::protectedPath($directory, true);
        if ((fileperms($directory) & 0077) !== 0) {
            throw new Fault('unsafe_path', 'Credential directory must be owner-only.');
        }
        $hex = trim(Files::readPrivate($keyFile, 128));
        Validate::digest($hex);
        $this->key = sodium_hex2bin($hex);
    }

    public function credentials(string $workspace): array
    {
        Validate::uuid($workspace);
        return Files::lock($this->directory . '/.lock', function () use ($workspace): array {
            $path = $this->directory . '/' . $workspace . '.json';
            if (is_file($path) || is_link($path)) {
                return $this->read($path)['credentials'];
            }
            $credentials = [
                'admin_username' => 'builder' . strtr(bin2hex(random_bytes(6)), '0123456789', 'ghijklmnop'),
                'admin_password' => bin2hex(random_bytes(24)),
                'database_password' => bin2hex(random_bytes(24)),
                'database_root_password' => bin2hex(random_bytes(24)),
            ];
            $this->write($path, ['revealed' => false, 'credentials' => $credentials]);
            return $credentials;
        });
    }

    public function reveal(string $workspace): array
    {
        Validate::uuid($workspace);
        return Files::lock($this->directory . '/.lock', function () use ($workspace): array {
            $path = $this->directory . '/' . $workspace . '.json';
            $record = $this->read($path);
            if ($record['revealed']) {
                throw new Fault('already_revealed', 'Initial credentials have already been retrieved.');
            }
            $record['revealed'] = true;
            $this->write($path, $record);
            return array_intersect_key($record['credentials'], array_flip(['admin_username', 'admin_password']));
        });
    }

    public function forget(string $workspace): void
    {
        Validate::uuid($workspace);
        Files::lock($this->directory . '/.lock', function () use ($workspace): void {
            $path = $this->directory . '/' . $workspace . '.json';
            Files::protectedPath($path);
            if (is_file($path) && !unlink($path)) {
                throw new Fault('credential_cleanup_failed', 'Unable to remove workspace credential envelope.');
            }
        });
    }

    private function read(string $path): array
    {
        $record = Json::decode(Files::readPrivate($path));
        $nonce = base64_decode($record['nonce'] ?? '', true);
        $cipher = base64_decode($record['ciphertext'] ?? '', true);
        if ($nonce === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || $cipher === false) {
            throw new Fault('invalid_envelope', 'Invalid credential envelope.');
        }
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new Fault('invalid_envelope', 'Credential envelope authentication failed.');
        }
        return Json::decode($plain);
    }

    private function write(string $path, array $record): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        Files::write($path, Json::encode(['version' => 1, 'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode(sodium_crypto_secretbox(Json::encode($record), $nonce, $this->key))]));
    }
}

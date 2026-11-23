<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;

/**
 * Creates the panel's SSH key (Ed25519) if it does not exist yet and prints
 * the public key. An existing key is never replaced: the servers trust it.
 * The public key goes to `setup-agent --public-key-file` on each server.
 *
 *   php artisan owpanel:ssh-key
 */
class CreateSshKey extends Command
{
    protected $signature = 'owpanel:ssh-key';

    protected $description = 'Create the SSH key of the panel and print its public key';

    public function handle(): int
    {
        $path = (string) config('owpanel.ssh.private_key');

        if (file_exists($path)) {
            $key = PublicKeyLoader::loadPrivateKey((string) file_get_contents($path));
            $this->info("The SSH key exists: {$path}");
        } else {
            $key = EC::createKey('Ed25519');
            $this->write($path, $key->toString('OpenSSH'));
            $this->info("SSH key created: {$path}");
        }

        $this->line('Public key for setup-agent --public-key-file:');
        $this->line($key->getPublicKey()->toString('OpenSSH', ['comment' => 'owpanel']));

        return self::SUCCESS;
    }

    /**
     * Writes the key readable only by the current user, in one step.
     */
    private function write(string $path, string $content): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        $old = umask(0077);
        try {
            $tmp = tempnam($dir, '.key');
            if ($tmp === false || file_put_contents($tmp, $content) === false || ! chmod($tmp, 0600) || ! rename($tmp, $path)) {
                throw new RuntimeException("Cannot write {$path}");
            }
        } finally {
            umask($old);
        }
    }
}

<?php

namespace Tests\Unit;

use App\Servers\HostKey;
use Database\Factories\ServerFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HostKeyTest extends TestCase
{
    public function test_drops_the_comment(): void
    {
        $key = ServerFactory::hostKey(str_repeat('a', 32));

        $this->assertSame($key, HostKey::normalize($key.' root@web1'));
        $this->assertSame($key, HostKey::normalize("  {$key}\n"));
    }

    public function test_fingerprint_matches_ssh_keygen(): void
    {
        // ssh-keygen -lf shows the unpadded base64 SHA-256 of the key blob.
        $key = ServerFactory::hostKey(str_repeat("\x01", 32));
        $blob = base64_decode(explode(' ', $key)[1]);

        $this->assertSame('SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='), HostKey::fingerprint($key));
        $this->assertStringStartsWith('SHA256:', HostKey::fingerprint($key));
        $this->assertStringEndsNotWith('=', HostKey::fingerprint($key));
    }

    public function test_matches_only_the_same_key(): void
    {
        $key = ServerFactory::hostKey(str_repeat('a', 32));
        $other = ServerFactory::hostKey(str_repeat('b', 32));

        $this->assertTrue(HostKey::matches($key, $key.' comment'));
        $this->assertFalse(HostKey::matches($key, $other));
        $this->assertFalse(HostKey::matches($key, ''));
        $this->assertFalse(HostKey::matches($key, 'ssh-rsa AAAAB3NzaC1yc2E'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        $valid = ServerFactory::hostKey(str_repeat('a', 32));
        $blob = explode(' ', $valid)[1];

        return [
            'empty' => [''],
            'type only' => ['ssh-ed25519'],
            'rsa' => ['ssh-rsa '.$blob],
            'ecdsa' => ['ecdsa-sha2-nistp256 '.$blob],
            'not base64' => ['ssh-ed25519 not*base64'],
            'short key' => ['ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).'short')],
            'long key' => ['ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('a', 33))],
            'blob of another type' => ['ssh-ed25519 '.base64_encode(pack('N', 7).'ssh-rsa'.pack('N', 32).str_repeat('a', 32))],
            'two keys' => [$valid."\n".$valid],
            'options' => ['command="/bin/sh" '.$valid],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_rejects_invalid_keys(string $key): void
    {
        $this->assertFalse(HostKey::isValid($key));

        $this->expectException(InvalidArgumentException::class);
        HostKey::normalize($key);
    }
}

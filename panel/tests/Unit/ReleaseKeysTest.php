<?php

namespace Tests\Unit;

use App\Support\ReleaseKeys;
use PHPUnit\Framework\TestCase;

class ReleaseKeysTest extends TestCase
{
    /** Fingerprint as ssh-keygen -l shows it for this key. */
    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOe3rYOKp4b8QsHyUJ43Ef/SCOQbiGmFG5N/eCpW0hOp';

    private const FINGERPRINT = 'SHA256:X0bCyQfl6ua6+ZdUXZc1hgJw8yObPYPgOtE0vEwdKMA';

    public function test_fingerprints_match_ssh_keygen(): void
    {
        $signers = "# comment\n\nowpanel-release namespaces=\"owpanel-release\" ".self::KEY." comment\n"
            .'owpanel-release '.self::KEY."\n";

        $this->assertSame([self::FINGERPRINT], ReleaseKeys::fingerprints($signers));
    }

    public function test_other_principals_and_broken_lines_are_ignored(): void
    {
        $signers = 'someone-else '.self::KEY."\n"
            ."owpanel-release namespaces=\"owpanel-release\" ssh-ed25519 not*base64\n"
            ."owpanel-release namespaces=\"owpanel-release\"\n"
            .'# owpanel-release '.self::KEY."\n";

        $this->assertSame([], ReleaseKeys::fingerprints($signers));
    }
}

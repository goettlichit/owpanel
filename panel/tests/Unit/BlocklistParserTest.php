<?php

namespace Tests\Unit;

use App\Firewall\BlocklistParser;
use PHPUnit\Framework\TestCase;

class BlocklistParserTest extends TestCase
{
    public function test_text_lists_with_comments_are_read(): void
    {
        $result = BlocklistParser::parse(implode("\n", [
            '# FireHOL style',
            '; Spamhaus DROP style',
            '// another comment',
            '185.220.101.7',
            '45.155.205.17/24 ; SBL123',
            '91.92.240.0/22,comment',
            "2a0e:1d80::/32\t# IPv6",
            '185.220.101.7',
            '',
        ]));

        $this->assertTrue($result['ok']);
        $this->assertSame(['185.220.101.7/32', '45.155.205.0/24', '91.92.240.0/22', '2a0e:1d80::/32'], $result['entries']);
        $this->assertSame(0, $result['ignored']);
        $this->assertSame(0, $result['invalid']);
    }

    public function test_json_lines_with_cidr_are_read(): void
    {
        $result = BlocklistParser::parse("{\"cidr\":\"45.155.205.0/24\",\"sblid\":\"SBL1\",\"rir\":\"ripencc\"}\n{\"type\":\"metadata\",\"timestamp\":1}\n");

        $this->assertTrue($result['ok']);
        $this->assertSame(['45.155.205.0/24'], $result['entries']);
    }

    public function test_private_reserved_and_huge_networks_are_left_out(): void
    {
        $result = BlocklistParser::parse("10.0.0.0/8\n192.168.1.1\n127.0.0.1\n0.0.0.0/0\n1.0.0.0/7\n2a00::/12\n::1\nfe80::/10\n185.220.101.0/24\n");

        $this->assertTrue($result['ok']);
        $this->assertSame(['185.220.101.0/24'], $result['entries']);
        $this->assertSame(7, $result['ignored']);
        $this->assertSame(1, $result['invalid']);
    }

    public function test_other_text_is_not_a_list(): void
    {
        $this->assertFalse(BlocklistParser::parse("<!DOCTYPE html>\n<html>\n<body>Not found</body>\n</html>\n")['ok']);
        $this->assertFalse(BlocklistParser::parse('')['ok']);
        $this->assertFalse(BlocklistParser::parse("# only comments\n")['ok']);

        // A few broken lines in a long list are fine, many are not.
        $good = implode("\n", array_map(fn (int $i) => "185.220.{$i}.0/24", range(1, 100)));
        $this->assertTrue(BlocklistParser::parse($good."\nfoo\nbar\n")['ok']);
        $this->assertFalse(BlocklistParser::parse($good."\n".str_repeat("garbage\n", 20))['ok']);
    }

    public function test_the_entry_containing_an_address_is_found(): void
    {
        $entries = ['185.220.101.0/24', '45.155.205.128/25', '2a0e:1d80::/32'];

        $this->assertSame('45.155.205.128/25', BlocklistParser::firstContaining($entries, '45.155.205.200'));
        $this->assertNull(BlocklistParser::firstContaining($entries, '45.155.205.100'));
        $this->assertSame('2a0e:1d80::/32', BlocklistParser::firstContaining($entries, '2a0e:1d80:ffff::1'));
        $this->assertNull(BlocklistParser::firstContaining($entries, '2a0e:1d81::1'));
    }
}

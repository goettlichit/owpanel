<?php

namespace Tests\Unit;

use App\Support\DnsQuery;
use PHPUnit\Framework\TestCase;

class DnsQueryTest extends TestCase
{
    private static function answer(int $id, int $flags, string $name, int $type, array $records): string
    {
        $question = DnsQuery::question($id, $name, $type);
        $packet = pack('nnnnnn', $id, $flags, 1, count($records), 0, 0).substr($question, 12);
        foreach ($records as [$recordType, $data]) {
            // Name as a pointer to the question at offset 12.
            $packet .= pack('nnnNn', 0xC00C, $recordType, 1, 300, strlen($data)).$data;
        }

        return $packet;
    }

    public function test_question_asks_without_recursion(): void
    {
        $packet = DnsQuery::question(0x1234, 'www.example.de', DnsQuery::A);

        $this->assertSame("\x12\x34\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00", substr($packet, 0, 12));
        $this->assertSame("\x03www\x07example\x02de\x00\x00\x01\x00\x01", substr($packet, 12));
    }

    public function test_parses_a_and_aaaa_answers(): void
    {
        $a = self::answer(7, 0x8400, 'example.de', DnsQuery::A, [[DnsQuery::A, inet_pton('203.0.113.10')], [5, "\x00"]]);
        $aaaa = self::answer(8, 0x8400, 'example.de', DnsQuery::AAAA, [[DnsQuery::AAAA, inet_pton('2001:db8::10')]]);

        $this->assertSame(['203.0.113.10'], DnsQuery::parse($a, 7, DnsQuery::A));
        $this->assertSame(['2001:db8::10'], DnsQuery::parse($aaaa, 8, DnsQuery::AAAA));
    }

    public function test_nxdomain_is_empty_and_errors_are_null(): void
    {
        $this->assertSame([], DnsQuery::parse(self::answer(1, 0x8403, 'x.de', DnsQuery::A, []), 1, DnsQuery::A));
        $this->assertSame([], DnsQuery::parse(self::answer(1, 0x8400, 'x.de', DnsQuery::A, []), 1, DnsQuery::A));
        $this->assertNull(DnsQuery::parse(self::answer(1, 0x8402, 'x.de', DnsQuery::A, []), 1, DnsQuery::A));
        $this->assertNull(DnsQuery::parse(self::answer(2, 0x8400, 'x.de', DnsQuery::A, []), 1, DnsQuery::A));
        $this->assertNull(DnsQuery::parse('short', 1, DnsQuery::A));
    }
}

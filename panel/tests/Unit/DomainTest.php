<?php

namespace Tests\Unit;

use App\Sites\Domain;
use PHPUnit\Framework\TestCase;

class DomainTest extends TestCase
{
    public function test_accepts_the_same_domains_as_site_create(): void
    {
        foreach (['example.de', 'shop.example.de', 'a-b.example.co.uk', 'xn--mller-kva.de', 'example.xn--p1ai', '1und1.de'] as $domain) {
            $this->assertTrue(Domain::isValid($domain), $domain);
        }
    }

    public function test_rejects_what_site_create_rejects(): void
    {
        $invalid = [
            'Example.de', 'localhost', 'a.d', '192.0.2.1', 'example.123', '-a.example.de', 'a-.example.de',
            'a..de', '.example.de', 'example.de.', 'a_b.de', 'a b.de', 'example.de/x', '*.example.de',
            str_repeat('a', 64).'.de', str_repeat('a.', 127).'de',
        ];

        foreach ($invalid as $domain) {
            $this->assertFalse(Domain::isValid($domain), $domain);
        }
    }

    public function test_normalises_case_spaces_and_internationalised_names(): void
    {
        $this->assertSame('shop.example.de', Domain::normalize(' Shop.EXAMPLE.de '));
        $this->assertSame('xn--mller-kva.de', Domain::normalize('Müller.de'));
        $this->assertSame('xn--strae-oqa.de', Domain::normalize('straße.de'));
        $this->assertNull(Domain::normalize('https://example.de'));
        $this->assertNull(Domain::normalize(''));
    }

    public function test_displays_punycode_in_unicode(): void
    {
        $this->assertSame('müller.de', Domain::display('xn--mller-kva.de'));
        $this->assertSame('example.de', Domain::display('example.de'));
    }
}

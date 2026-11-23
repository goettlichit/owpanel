<?php

namespace Tests\Unit;

use App\Support\DnsResolver;
use Mockery;
use Tests\TestCase;

class DnsResolverTest extends TestCase
{
    private function resolver(array $addresses): DnsResolver
    {
        $resolver = Mockery::mock(DnsResolver::class)->makePartial();
        $resolver->shouldReceive('addresses')->andReturn($addresses);

        return $resolver;
    }

    public function test_every_address_must_belong_to_the_server(): void
    {
        $own = ['203.0.113.10', '2001:db8::10'];

        $this->assertTrue($this->resolver(['203.0.113.10'])->pointsTo('a.test', $own));
        $this->assertTrue($this->resolver(['203.0.113.10', '2001:db8::10'])->pointsTo('a.test', $own));
        $this->assertFalse($this->resolver(['203.0.113.10', '2001:db8::99'])->pointsTo('a.test', $own));
        $this->assertFalse($this->resolver(['198.51.100.7'])->pointsTo('a.test', $own));
        $this->assertFalse($this->resolver([])->pointsTo('a.test', $own));
        $this->assertFalse($this->resolver(['203.0.113.10'])->pointsTo('a.test', [null]));
    }

    public function test_ipv6_spellings_are_compared_normalised(): void
    {
        $this->assertTrue($this->resolver(['2001:db8::10'])->pointsTo('a.test', ['2001:0db8:0000:0000:0000:0000:0000:0010']));
    }
}

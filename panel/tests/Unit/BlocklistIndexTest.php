<?php

namespace Tests\Unit;

use App\Firewall\BlocklistIndex;
use PHPUnit\Framework\TestCase;

class BlocklistIndexTest extends TestCase
{
    public function test_addresses_in_networks_and_hosts_are_found(): void
    {
        $index = new BlocklistIndex(['185.220.101.0/24', '45.155.205.17/32', '91.92.240.0/22', '2a0e:1d80::/32', '2001:4860:4860::8888/128']);

        foreach (['185.220.101.200', '45.155.205.17', '91.92.243.255', '2a0e:1d80:1::5', '2001:4860:4860::8888'] as $address) {
            $this->assertTrue($index->contains($address), $address);
        }
        foreach (['185.220.102.1', '45.155.205.18', '91.92.244.0', '2a0e:1d81::1', '2001:4860:4860::8844', 'not an ip'] as $address) {
            $this->assertFalse($index->contains($address), $address);
        }
    }
}

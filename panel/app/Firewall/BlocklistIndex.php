<?php

namespace App\Firewall;

/**
 * Finds whether an address is on a blocklist (E-170) with one hash lookup
 * per prefix length in the list, instead of comparing it with every entry;
 * lists have up to 250000 entries.
 */
final class BlocklistIndex
{
    /** @var array<int, array<int, array<string, true>>> address length => prefix => masked network */
    private array $networks = [];

    /**
     * @param  list<string>  $entries  networks with prefix
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            [$address, $prefix] = explode('/', $entry, 2) + [1 => ''];
            $binary = @inet_pton($address);
            if ($binary === false || ! ctype_digit($prefix)) {
                continue;
            }
            $bits = (int) $prefix;
            $this->networks[strlen($binary)][$bits][self::mask($binary, $bits)] = true;
        }
    }

    /**
     * The networks by address length and prefix, for the cache.
     *
     * @return array<int, array<int, array<string, true>>>
     */
    public function networks(): array
    {
        return $this->networks;
    }

    /**
     * @param  array<int, array<int, array<string, true>>>  $networks  from networks()
     */
    public static function fromNetworks(array $networks): self
    {
        $index = new self([]);
        $index->networks = $networks;

        return $index;
    }

    public function contains(string $address): bool
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return false;
        }
        foreach ($this->networks[strlen($binary)] ?? [] as $bits => $masked) {
            if (isset($masked[self::mask($binary, $bits)])) {
                return true;
            }
        }

        return false;
    }

    private static function mask(string $binary, int $bits): string
    {
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        $masked = substr($binary, 0, $bytes);
        if ($rest > 0 && $bytes < strlen($binary)) {
            $masked .= chr(ord($binary[$bytes]) & (0xFF << (8 - $rest)) & 0xFF);
        }

        return $masked;
    }
}

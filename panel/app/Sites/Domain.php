<?php

namespace App\Sites;

/**
 * Domain names of websites.
 *
 * The rules match owp_valid_domain in server/lib/site.sh: lowercase host
 * name with at least two labels, no IP addresses, no trailing dot. The panel
 * checks first so that the user gets a message in the form; site-create
 * checks again (docs/SICHERHEIT.md, "Eingaben").
 */
final class Domain
{
    private const LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    private const TLD = '/^([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    /**
     * Common endings with two parts, under which a main domain has three
     * labels (example.co.uk). Not the full public suffix list: a short list
     * that one person can keep up to date (E-120).
     */
    public const MULTI_PART_SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'ac.uk', 'gov.uk',
        'co.at', 'or.at', 'ac.at', 'gv.at',
        'com.au', 'net.au', 'org.au', 'co.nz', 'net.nz', 'org.nz',
        'co.za', 'com.br', 'com.tr', 'com.mx', 'com.ar', 'com.cn', 'com.hk', 'com.sg', 'com.tw',
        'co.jp', 'ne.jp', 'or.jp', 'co.kr', 'co.in', 'co.il', 'com.pl', 'com.es', 'com.pt',
    ];

    /**
     * Whether a domain is a main domain such as example.de or example.co.uk
     * and not a subdomain such as shop.example.de (E-120).
     */
    public static function isMain(string $domain): bool
    {
        $labels = explode('.', $domain);
        if (count($labels) === 2) {
            return true;
        }

        return count($labels) === 3 && in_array($labels[1].'.'.$labels[2], self::MULTI_PART_SUFFIXES, true);
    }

    /**
     * Turns user input into the stored form: trimmed, lowercase, and
     * internationalised names such as "müller.de" as punycode
     * ("xn--mller-kva.de"). Returns null if the result is not a valid domain.
     */
    public static function normalize(string $input): ?string
    {
        $domain = mb_strtolower(trim($input));

        if ($domain !== '' && preg_match('/[^\x20-\x7e]/', $domain)) {
            if (! function_exists('idn_to_ascii')) {
                return null;
            }

            $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $domain = $ascii;
        }

        return self::isValid($domain) ? $domain : null;
    }

    public static function isValid(string $domain): bool
    {
        $length = strlen($domain);
        if ($length < 4 || $length > 253 || ! preg_match('/^[a-z0-9.-]+$/', $domain)) {
            return false;
        }

        if (str_starts_with($domain, '.') || str_ends_with($domain, '.') || str_contains($domain, '..')) {
            return false;
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (! preg_match(self::LABEL, $label)) {
                return false;
            }
        }

        return (bool) preg_match(self::TLD, end($labels));
    }

    /**
     * Readable form for the interface: punycode back to Unicode.
     */
    public static function display(string $domain): string
    {
        if (! str_contains($domain, 'xn--') || ! function_exists('idn_to_utf8')) {
            return $domain;
        }

        $unicode = idn_to_utf8($domain, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

        return $unicode === false ? $domain : $unicode;
    }
}

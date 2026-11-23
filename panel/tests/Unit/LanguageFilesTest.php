<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * German and English are equal (E-002): every key must exist in both
 * languages, and no value may be empty.
 */
class LanguageFilesTest extends TestCase
{
    private const LOCALES = ['de', 'en'];

    public function test_all_languages_have_the_same_files(): void
    {
        $files = array_map(fn (string $locale) => $this->files($locale), self::LOCALES);

        $this->assertNotEmpty($files[0]);
        $this->assertSame($files[0], $files[1]);
    }

    public function test_all_languages_have_the_same_keys(): void
    {
        foreach ($this->files('de') as $file) {
            $keys = array_map(
                fn (string $locale) => $this->flatten(require $this->langPath($locale).'/'.$file),
                self::LOCALES,
            );

            $this->assertSame(array_keys($keys[0]), array_keys($keys[1]), "Keys differ in {$file}");

            foreach ($keys as $values) {
                foreach ($values as $key => $value) {
                    $this->assertNotSame('', trim((string) $value), "Empty text for {$key} in {$file}");
                }
            }
        }
    }

    public function test_json_translations_have_the_same_keys(): void
    {
        $keys = array_map(function (string $locale) {
            $values = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/lang/'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
            ksort($values);

            return array_keys($values);
        }, self::LOCALES);

        $this->assertSame($keys[0], $keys[1]);
    }

    private function langPath(string $locale): string
    {
        return dirname(__DIR__, 2).'/lang/'.$locale;
    }

    /** @return list<string> */
    private function files(string $locale): array
    {
        $files = array_map('basename', glob($this->langPath($locale).'/*.php') ?: []);
        sort($files);

        return $files;
    }

    /** @return array<string, mixed> */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix.$key.'.');
            } else {
                $flat[$prefix.$key] = $value;
            }
        }

        ksort($flat);

        return $flat;
    }
}

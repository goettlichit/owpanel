<?php

namespace Tests;

use App\Support\DnsResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    /**
     * With true, parts that a page loads after itself (Livewire #[Defer],
     * such as the firewall's bans or a log) are rendered right into the
     * page, so a test can check them with the page. Test classes for such
     * pages turn it on; the others see the placeholders.
     */
    protected bool $renderDeferred = false;

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        if ($this->renderDeferred) {
            Livewire::withoutLazyLoading();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Livewire keeps "without lazy loading" in a static; every test starts without it.
        SupportLazyLoading::$disableWhileTesting = false;

        // Symfony sends "Accept-Language: en-us" with every test request.
        // Tests start without a browser language and set one where needed.
        $this->withHeader('Accept-Language', '');

        // No real DNS lookups in tests: names resolve to nothing unless a
        // test fakes an answer.
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function addresses(string $host): array
            {
                return [];
            }

            public function nameservers(string $host): array
            {
                return [];
            }
        });
    }
}

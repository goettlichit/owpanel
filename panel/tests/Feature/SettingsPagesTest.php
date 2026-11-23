<?php

namespace Tests\Feature;

use App\Hetzner\HetznerCloud;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Settings on their own pages under System (E-118) and the optional
 * Hetzner token for DNS.
 */
class SettingsPagesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ0123';

    private const DNS_TOKEN = 'zyxwvutsrqponmlkjihgfedcba9876543210ZYXWVUTSRQPONMLKJIHGFEDCBA9876';

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_each_topic_has_its_own_page_in_the_menu(): void
    {
        $admin = $this->admin();
        $session = ['auth.password_confirmed_at' => time()];

        $this->actingAs($admin)->withSession($session)->get('/settings')->assertOk()
            ->assertSee(route('settings.mail'))->assertSee(route('settings.oidc'))->assertSee(route('settings.services'))
            ->assertSee('imprint_url', false)->assertDontSee('mail_host', false)->assertDontSee('oidc_issuer', false);
        $this->actingAs($admin)->withSession($session)->get('/settings/mail')->assertOk()->assertSee('mail_host', false);
        $this->actingAs($admin)->withSession($session)->get('/settings/oidc')->assertOk()->assertSee('oidc_issuer', false);
        $this->actingAs($admin)->withSession($session)->get('/settings/services')->assertOk()
            ->assertSee('hetzner_token', false)->assertSee('hetzner_dns_token', false);
        $this->actingAs($admin)->withSession($session)->get('/settings/backup')->assertOk()
            ->assertDontSee('hetzner_token', false);
    }

    /**
     * External services (E-123): own title and icon in the System menu.
     */
    public function test_external_services_page_has_its_title_and_icon(): void
    {
        $this->actingAs($this->admin())->withSession(['auth.password_confirmed_at' => time()])
            ->get('/settings/services')->assertOk()
            ->assertSee(__('settings.services.title'))->assertSee('ti-plug-connected', false);
    }

    public function test_pages_open_without_a_password_prompt_and_are_for_administrators_only(): void
    {
        foreach (['/settings/mail', '/settings/oidc', '/settings/services'] as $page) {
            $this->actingAs($this->admin())->get($page)->assertOk();
        }

        $customer = User::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        foreach (['/settings/mail', '/settings/oidc', '/settings/services'] as $page) {
            $this->actingAs($customer)->withSession(['auth.password_confirmed_at' => time()])->get($page)->assertForbidden();
        }
        $this->actingAs($customer)->put('/settings/hetzner-dns', ['hetzner_dns_token' => self::DNS_TOKEN])->assertForbidden();
        $this->assertNull(Setting::get(Setting::HETZNER_DNS_TOKEN));
    }

    public function test_dns_token_is_checked_and_stored_encrypted(): void
    {
        Http::fake(['api.hetzner.cloud/v1/zones*' => Http::response(['zones' => []])]);
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/hetzner-dns', ['hetzner_dns_token' => self::DNS_TOKEN])
            ->assertRedirect('/settings/services')->assertSessionHas('status');

        $this->assertSame(self::DNS_TOKEN, Crypt::decryptString((string) Setting::get(Setting::HETZNER_DNS_TOKEN)));
        $this->assertTrue(HetznerCloud::dnsConfigured());
        $this->assertFalse(HetznerCloud::configured());
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.self::DNS_TOKEN));
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.hetzner_dns_token_changed', 'user_id' => $admin->id]);
    }

    public function test_rejected_dns_token_is_not_stored(): void
    {
        Http::fake(['api.hetzner.cloud/*' => Http::response(['error' => ['code' => 'unauthorized']], 401)]);

        $this->actingAs($this->admin())->put('/settings/hetzner-dns', ['hetzner_dns_token' => self::DNS_TOKEN])
            ->assertSessionHasErrors('hetzner_dns_token');
        $this->actingAs($this->admin())->put('/settings/hetzner-dns', ['hetzner_dns_token' => 'short'])
            ->assertSessionHasErrors('hetzner_dns_token');
        $this->assertNull(Setting::get(Setting::HETZNER_DNS_TOKEN));
    }

    public function test_dns_uses_its_own_token_and_falls_back_to_the_project_token(): void
    {
        Http::fake(['api.hetzner.cloud/*' => Http::response(['zones' => []])]);
        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString(self::TOKEN));

        HetznerCloud::forDns()->verifyZones();
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));

        Setting::put(Setting::HETZNER_DNS_TOKEN, Crypt::encryptString(self::DNS_TOKEN));
        HetznerCloud::forDns()->verifyZones();
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.self::DNS_TOKEN));

        $admin = $this->admin();
        $this->actingAs($admin)->put('/settings/hetzner-dns', ['hetzner_dns_token_remove' => '1'])
            ->assertRedirect('/settings/services');
        $this->assertNull(Setting::get(Setting::HETZNER_DNS_TOKEN));
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.hetzner_dns_token_removed']);
    }
}

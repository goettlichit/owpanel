<?php

namespace Tests\Feature;

use App\Models\OidcIdentity;
use App\Models\Setting;
use App\Models\User;
use App\Oidc\OidcSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\OidcFake;
use Tests\TestCase;

/**
 * System -> Settings: login with Pocket ID (E-114).
 */
class OidcSettingsTest extends TestCase
{
    use RefreshDatabase;

    private OidcFake $idp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idp = new OidcFake;
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'oidc_name' => 'goettlichIT ID',
            'oidc_issuer' => OidcFake::ISSUER.'/',
            'oidc_client_id' => OidcFake::CLIENT_ID,
            'oidc_client_secret' => OidcFake::CLIENT_SECRET,
        ], $overrides);
    }

    public function test_the_card_shows_the_callback_url_and_no_secret(): void
    {
        $this->idp->fake();
        OidcSettings::save(OidcFake::ISSUER, OidcFake::CLIENT_ID, OidcFake::CLIENT_SECRET, 'goettlichIT ID');

        $this->actingAs($this->admin())->withSession(['auth.password_confirmed_at' => time()])
            ->get('/settings/oidc')
            ->assertOk()
            ->assertSee('Anmeldung mit Pocket ID')
            ->assertSee('value="goettlichIT ID"', false)
            ->assertSee('value="'.OidcFake::ISSUER.'"', false)
            ->assertSee(rtrim((string) config('app.url'), '/').'/auth/oidc/callback')
            ->assertSee('Verbindung prüfen')
            ->assertDontSee(OidcFake::CLIENT_SECRET);
    }

    public function test_settings_are_checked_and_saved_with_the_exact_issuer(): void
    {
        $this->idp->fake();
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/oidc', $this->form())
            ->assertRedirect('/settings/oidc')
            ->assertSessionHas('status', 'Anmeldung mit goettlichIT ID gespeichert, die Verbindung ist in Ordnung.');

        // The issuer as the provider names itself, without the slash typed.
        $this->assertSame(OidcFake::ISSUER, OidcSettings::issuer());
        $this->assertSame(OidcFake::CLIENT_ID, OidcSettings::clientId());
        $this->assertSame(OidcFake::CLIENT_SECRET, OidcSettings::clientSecret());
        $this->assertSame('goettlichIT ID', OidcSettings::name());
        $this->assertTrue(OidcSettings::configured());
        // Stored encrypted only.
        $stored = DB::table('settings')->where('key', Setting::OIDC_CLIENT_SECRET)->value('value');
        $this->assertNotSame(OidcFake::CLIENT_SECRET, $stored);
        $this->assertSame(OidcFake::CLIENT_SECRET, Crypt::decryptString($stored));
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.oidc_settings_changed', 'user_id' => $admin->id]);
        $this->assertStringNotContainsString(OidcFake::CLIENT_SECRET, (string) DB::table('audit_logs')->pluck('context')->implode(' '));
    }

    public function test_an_empty_name_shows_pocket_id(): void
    {
        $this->idp->fake();

        $this->actingAs($this->admin())->put('/settings/oidc', $this->form(['oidc_name' => '']))->assertRedirect('/settings/oidc');

        $this->assertNull(OidcSettings::customName());
        $this->assertSame('Pocket ID', OidcSettings::name());
    }

    public function test_an_empty_secret_keeps_the_stored_one_and_is_required_at_first(): void
    {
        $this->idp->fake();
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/oidc', $this->form(['oidc_client_secret' => '']))
            ->assertSessionHasErrors('oidc_client_secret');
        $this->assertFalse(OidcSettings::configured());

        OidcSettings::save(OidcFake::ISSUER, OidcFake::CLIENT_ID, OidcFake::CLIENT_SECRET, null);
        $this->actingAs($admin)->put('/settings/oidc', $this->form(['oidc_client_secret' => '', 'oidc_name' => 'Neu']))
            ->assertRedirect('/settings/oidc');
        $this->assertSame(OidcFake::CLIENT_SECRET, OidcSettings::clientSecret());
        $this->assertSame('Neu', OidcSettings::name());
    }

    public function test_a_provider_that_fails_the_checks_is_not_saved(): void
    {
        $admin = $this->admin();

        $this->idp->fake(['issuer' => 'https://other.example.test']);
        $this->actingAs($admin)->from('/settings/oidc')->put('/settings/oidc', $this->form())
            ->assertRedirect('/settings/oidc')
            ->assertSessionHasErrors(['oidc_issuer' => 'Die Instanz nennt sich selbst unter einer anderen Adresse. Bitte genau ihre Adresse eintragen.'])
            ->assertSessionHasInput('oidc_client_id')
            ->assertSessionMissing('_old_input.oidc_client_secret');

        $this->idp->fake(['code_challenge_methods_supported' => ['plain']]);
        $this->actingAs($admin)->put('/settings/oidc', $this->form())->assertSessionHasErrors('oidc_issuer');

        $this->idp->fake(keys: []);
        $this->actingAs($admin)->put('/settings/oidc', $this->form())->assertSessionHasErrors('oidc_issuer');

        $this->assertNull(OidcSettings::issuer());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'system.oidc_settings_changed']);
    }

    public function test_only_https_addresses_and_plain_values_are_accepted(): void
    {
        Http::fake();
        $admin = $this->admin();

        foreach (['http://id.example.test', 'id.example.test', 'https://id.example.test/?a=1', 'javascript:alert(1)'] as $address) {
            $this->actingAs($admin)->put('/settings/oidc', $this->form(['oidc_issuer' => $address]))->assertSessionHasErrors('oidc_issuer');
        }
        $this->actingAs($admin)->put('/settings/oidc', $this->form(['oidc_client_id' => 'a b']))->assertSessionHasErrors('oidc_client_id');
        $this->actingAs($admin)->put('/settings/oidc', $this->form(['oidc_client_id' => '']))->assertSessionHasErrors('oidc_client_id');
        $this->actingAs($admin)->put('/settings/oidc', $this->form(['oidc_name' => "a\nb"]))->assertSessionHasErrors('oidc_name');
        $this->actingAs($admin)->put('/settings/oidc', $this->form(['oidc_name' => str_repeat('x', 51)]))->assertSessionHasErrors('oidc_name');

        Http::assertNothingSent();
        $this->assertNull(OidcSettings::issuer());
    }

    public function test_an_empty_address_turns_the_login_off_and_keeps_links(): void
    {
        $admin = $this->admin();
        OidcSettings::save(OidcFake::ISSUER, OidcFake::CLIENT_ID, OidcFake::CLIENT_SECRET, 'goettlichIT ID');
        $admin->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'user-1234']);

        $this->actingAs($admin)->put('/settings/oidc', ['oidc_name' => 'goettlichIT ID', 'oidc_issuer' => ''])
            ->assertRedirect('/settings/oidc')
            ->assertSessionHas('status', 'Anmeldung mit Pocket ID abgeschaltet.');

        $this->assertFalse(OidcSettings::configured());
        $this->assertNull(OidcSettings::clientSecret());
        $this->assertSame('goettlichIT ID', OidcSettings::name());
        $this->assertSame(1, OidcIdentity::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.oidc_disabled']);
    }

    public function test_the_connection_check_reports_success_and_errors(): void
    {
        $admin = $this->admin();
        OidcSettings::save(OidcFake::ISSUER, OidcFake::CLIENT_ID, OidcFake::CLIENT_SECRET, null);

        $this->idp->fake();
        $this->actingAs($admin)->post('/settings/oidc/test')
            ->assertRedirect('/settings/oidc')
            ->assertSessionHas('status', 'Verbindung zu https://id.example.test in Ordnung: Signatur mit RS256, ES256, 2 Schlüssel.');

        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->actingAs($admin)->post('/settings/oidc/test')
            ->assertSessionHas('error', 'Prüfung fehlgeschlagen: Keine Verbindung zur Instanz. Stimmt die Adresse, und ist sie erreichbar?');
    }

    public function test_the_check_needs_settings(): void
    {
        Http::fake();

        $this->actingAs($this->admin())->post('/settings/oidc/test')
            ->assertSessionHas('error', 'Prüfung fehlgeschlagen: Die Anmeldung mit Pocket ID ist noch nicht eingerichtet.');
        Http::assertNothingSent();
    }

    public function test_customers_cannot_change_the_login_settings(): void
    {
        Http::fake();
        $customer = User::factory()->create();

        $this->actingAs($customer)->put('/settings/oidc', $this->form())->assertForbidden();
        $this->actingAs($customer)->post('/settings/oidc/test')->assertForbidden();

        $this->assertNull(OidcSettings::issuer());
        Http::assertNothingSent();
    }

    public function test_identities_are_unique_per_issuer_and_subject_and_removed_with_the_account(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $admin->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'user-1234']);

        try {
            $other->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'user-1234']);
            $this->fail('Two accounts got the same login.');
        } catch (QueryException) {
        }

        $admin->delete();
        $this->assertSame(0, OidcIdentity::count());
    }
}

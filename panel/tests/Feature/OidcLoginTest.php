<?php

namespace Tests\Feature;

use App\Models\OidcIdentity;
use App\Models\User;
use App\Oidc\OidcSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\OidcFake;
use Tests\TestCase;

/**
 * Login with Pocket ID and linking of the own account (E-114,
 * docs/SICHERHEIT.md "Pocket ID und OIDC").
 */
class OidcLoginTest extends TestCase
{
    use RefreshDatabase;

    private OidcFake $idp;

    /** @var array<string, mixed> claims the fake provider puts in the next token */
    private array $tokenClaims = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->idp = new OidcFake;
        OidcSettings::save(OidcFake::ISSUER, OidcFake::CLIENT_ID, OidcFake::CLIENT_SECRET, 'goettlichIT ID');
    }

    /**
     * Starts a flow and fakes the provider so the token carries the nonce
     * of this flow. Returns the state.
     */
    private function start(TestResponse $response): string
    {
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(OidcFake::ISSUER.'/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->idp->fake(more: [
            OidcFake::ISSUER.'/api/oidc/token' => fn () => Http::response([
                'id_token' => $this->idp->token(array_merge(['nonce' => $query['nonce']], $this->tokenClaims)),
            ]),
        ]);

        return $query['state'];
    }

    private function returnFromIdp(string $state, string $code = 'the-code'): TestResponse
    {
        return $this->get('/auth/oidc/callback?'.http_build_query(['code' => $code, 'state' => $state]));
    }

    private function linkedCustomer(): User
    {
        $user = User::factory()->create(['oidc_allowed' => true]);
        $user->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'user-1234']);

        return $user;
    }

    public function test_the_login_page_shows_the_button_only_when_set_up(): void
    {
        $this->get('/login')->assertOk()->assertSee('Anmelden mit goettlichIT ID')->assertSee(route('oidc.redirect'));

        OidcSettings::disable('goettlichIT ID');
        $this->get('/login')->assertOk()->assertDontSee('goettlichIT ID')->assertDontSee(route('oidc.redirect'));
    }

    public function test_a_linked_customer_logs_in(): void
    {
        $this->idp->fake();
        $user = $this->linkedCustomer();

        $state = $this->start($this->get('/auth/oidc/redirect'));
        $this->returnFromIdp($state)->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('oidc', session('auth.via'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.oidc_login', 'user_id' => $user->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.login']);
        $identity = OidcIdentity::first();
        $this->assertNotNull($identity->last_login_at);
        $this->assertSame('max@example.test', $identity->email);
        $this->assertNotNull($user->fresh()->last_login_at);

        // PKCE: the token request carries the verifier for the challenge sent before.
        Http::assertSent(fn (Request $request) => $request->url() === OidcFake::ISSUER.'/api/oidc/token'
            && strlen((string) $request['code_verifier']) === 43
            && $request['redirect_uri'] === OidcSettings::redirectUrl());
    }

    public function test_an_admin_without_panel_two_factor_may_use_the_panel_after_a_pocket_id_login(): void
    {
        $this->idp->fake();
        $admin = User::factory()->admin()->create();
        $admin->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'user-1234']);

        $state = $this->start($this->get('/auth/oidc/redirect'));
        $this->returnFromIdp($state)->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->get('/')->assertOk();
        $this->get('/settings')->assertOk();
    }

    public function test_an_unknown_account_is_not_created_and_not_logged_in(): void
    {
        $this->idp->fake();
        User::factory()->create(['email' => 'max@example.test']);

        $state = $this->start($this->get('/auth/oidc/redirect'));
        $this->returnFromIdp($state)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Dieses Konto bei goettlichIT ID ist mit keinem Panel-Konto verknüpft. Melden Sie sich mit Passwort an und verknüpfen Sie es unter Mein Konto → Anmeldung und Sicherheit.');

        // Never matched by e-mail address.
        $this->assertGuest();
        $this->assertSame(1, User::count());
        $this->assertSame(0, OidcIdentity::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.oidc_failed', 'user_id' => null]);
    }

    public function test_a_link_to_an_earlier_issuer_does_not_log_in(): void
    {
        $this->idp->fake();
        $user = User::factory()->create(['oidc_allowed' => true]);
        $user->oidcIdentities()->create(['issuer' => 'https://old.example.test', 'subject' => 'user-1234']);

        $state = $this->start($this->get('/auth/oidc/redirect'));
        $this->returnFromIdp($state)->assertRedirect(route('login'))->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_a_wrong_state_is_refused_before_the_code_is_used(): void
    {
        $this->idp->fake();
        $this->linkedCustomer();

        $this->start($this->get('/auth/oidc/redirect'));
        $this->returnFromIdp('forged-state')->assertRedirect(route('login'))->assertSessionHas('error');

        $this->assertGuest();
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/token'));
    }

    public function test_a_callback_works_only_once(): void
    {
        $this->idp->fake();
        $this->linkedCustomer();

        $state = $this->start($this->get('/auth/oidc/redirect'));
        $this->returnFromIdp($state)->assertRedirect(route('dashboard'));
        $this->post('/logout');

        $this->returnFromIdp($state)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Die Anmeldung hat zu lange gedauert oder wurde neu gestartet. Bitte noch einmal.');
        $this->assertGuest();
    }

    public function test_a_callback_without_a_started_flow_or_too_late_is_refused(): void
    {
        $this->idp->fake();
        $this->linkedCustomer();

        $this->returnFromIdp('any-state')->assertRedirect(route('login'))->assertSessionHas('error');

        $state = $this->start($this->get('/auth/oidc/redirect'));
        $this->travel(11)->minutes();
        $this->returnFromIdp($state)->assertRedirect(route('login'))->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_a_cancelled_login_is_reported(): void
    {
        $this->idp->fake();
        $state = $this->start($this->get('/auth/oidc/redirect'));

        $this->get('/auth/oidc/callback?'.http_build_query(['error' => 'access_denied', 'state' => $state]))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Die Anmeldung bei goettlichIT ID wurde abgebrochen.');
        $this->assertGuest();
    }

    public function test_an_invalid_token_does_not_log_in(): void
    {
        $this->idp->fake();
        $this->linkedCustomer();

        foreach ([['nonce' => 'other-nonce'], ['aud' => 'other-client'], ['iss' => 'https://evil.example.test']] as $claims) {
            $this->tokenClaims = $claims;
            $state = $this->start($this->get('/auth/oidc/redirect'));
            $this->returnFromIdp($state)
                ->assertRedirect(route('login'))
                ->assertSessionHas('error', 'Die Anmeldung mit goettlichIT ID ist fehlgeschlagen. Bitte noch einmal oder mit Passwort anmelden.');
            $this->assertGuest();
        }
    }

    public function test_an_unreachable_provider_points_to_the_password(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->get('/auth/oidc/redirect')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'goettlichIT ID ist gerade nicht erreichbar. Bitte mit Passwort anmelden.');
    }

    public function test_the_login_needs_settings_and_a_guest(): void
    {
        Http::fake();
        $user = User::factory()->create(['oidc_allowed' => true]);

        $this->actingAs($user)->get('/auth/oidc/redirect')->assertRedirect();
        $this->assertNull(session('oidc.flow'));

        auth()->logout();
        OidcSettings::disable(null);
        $this->get('/auth/oidc/redirect')->assertRedirect(route('login'))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_an_account_links_itself_while_logged_in(): void
    {
        $this->idp->fake();
        $user = User::factory()->create(['oidc_allowed' => true]);

        $state = $this->start($this->actingAs($user)->post('/account/oidc'));
        $this->returnFromIdp($state)
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'Ihr Konto ist jetzt mit goettlichIT ID verknüpft.');

        $identity = $user->oidcIdentities()->sole();
        $this->assertSame([OidcFake::ISSUER, 'user-1234', 'max@example.test'], [$identity->issuer, $identity->subject, $identity->email]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.oidc_linked', 'user_id' => $user->id]);
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('auth.via'));
    }

    public function test_a_login_linked_to_another_account_cannot_be_taken(): void
    {
        $this->idp->fake();
        $owner = $this->linkedCustomer();
        $other = User::factory()->create(['oidc_allowed' => true]);

        $state = $this->start($this->actingAs($other)->withSession(['auth.password_confirmed_at' => time()])->post('/account/oidc'));
        $this->returnFromIdp($state)
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('error', 'Das Konto max@example.test bei goettlichIT ID ist schon mit einem anderen Panel-Konto verknüpft. Zum Verknüpfen bei goettlichIT ID mit dem passenden Konto anmelden, bei Bedarf vorher dort abmelden oder einen anderen Passkey wählen.');

        $this->assertSame($owner->id, OidcIdentity::sole()->user_id);
        $this->assertSame(0, $other->oidcIdentities()->count());
    }

    public function test_a_link_flow_belongs_to_the_account_that_started_it(): void
    {
        $this->idp->fake();
        $starter = User::factory()->create(['oidc_allowed' => true]);
        $other = User::factory()->create(['oidc_allowed' => true]);

        $state = $this->start($this->actingAs($starter)->withSession(['auth.password_confirmed_at' => time()])->post('/account/oidc'));
        $this->actingAs($other);
        $this->returnFromIdp($state)->assertRedirect(route('login'));

        $this->assertSame(0, OidcIdentity::count());
    }

    public function test_relinking_replaces_a_link_to_another_subject(): void
    {
        $this->idp->fake();
        $user = User::factory()->create(['oidc_allowed' => true]);
        $user->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'old-subject']);

        $state = $this->start($this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post('/account/oidc'));
        $this->returnFromIdp($state)->assertRedirect(route('account.security'));

        $this->assertSame('user-1234', $user->oidcIdentities()->sole()->subject);
    }

    public function test_an_account_removes_only_its_own_link(): void
    {
        $user = $this->linkedCustomer();
        $other = User::factory()->create(['oidc_allowed' => true]);
        $other->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'other-subject']);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->delete('/account/oidc')
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'Die Verknüpfung mit goettlichIT ID ist getrennt.');

        $this->assertSame(0, $user->oidcIdentities()->count());
        $this->assertSame(1, $other->oidcIdentities()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.oidc_unlinked', 'user_id' => $user->id]);
    }

    public function test_the_security_page_shows_the_link_state(): void
    {
        $user = User::factory()->create(['oidc_allowed' => true]);
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->withSession($confirmed)->get('/account/security')
            ->assertOk()->assertSee('Anmeldung mit goettlichIT ID')->assertSee('Mit goettlichIT ID verknüpfen')->assertDontSee('Verknüpfung trennen');

        $user->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'user-1234', 'email' => 'max@example.test']);
        $this->actingAs($user)->withSession($confirmed)->get('/account/security')
            ->assertOk()->assertSee('Verknüpft mit max@example.test')->assertSee('Verknüpfung trennen')->assertDontSee('Mit goettlichIT ID verknüpfen');

        OidcSettings::disable(null);
        $this->actingAs($user)->withSession($confirmed)->get('/account/security')
            ->assertOk()->assertSee('Verknüpfung trennen')->assertDontSee('verknüpfen</button>', false);

        $user->oidcIdentities()->delete();
        $this->actingAs($user)->withSession($confirmed)->get('/account/security')
            ->assertOk()->assertDontSee('Anmeldung mit Pocket ID');
    }

    public function test_the_password_login_does_not_count_as_pocket_id(): void
    {
        $user = User::factory()->create(['oidc_allowed' => true]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('auth.via'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_a_customer_without_the_switch_can_neither_sign_in_nor_link(): void
    {
        $this->idp->fake();
        $user = User::factory()->create();
        $user->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'user-1234']);

        $state = $this->start($this->get('/auth/oidc/redirect'));
        $this->returnFromIdp($state)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Die Anmeldung mit goettlichIT ID ist für Ihr Konto nicht freigeschaltet. Bitte mit Passwort anmelden.');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.oidc_failed', 'subject_id' => $user->id]);

        $other = User::factory()->create();
        $confirmed = ['auth.password_confirmed_at' => time()];
        $this->actingAs($other)->withSession($confirmed)->post('/account/oidc')
            ->assertRedirect(route('account.security'))->assertSessionHas('error');
        $this->assertNull(session('oidc.flow'));
        $this->actingAs($other)->withSession($confirmed)->get('/account/security')->assertOk()->assertDontSee('Mit goettlichIT ID verknüpfen');
        $this->actingAs($other)->get('/account')->assertOk()->assertDontSee('Noch nicht verknüpft.');
    }

    public function test_the_switch_is_checked_again_when_the_link_returns(): void
    {
        $this->idp->fake();
        $user = User::factory()->create(['oidc_allowed' => true]);

        $state = $this->start($this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post('/account/oidc'));
        $user->forceFill(['oidc_allowed' => false])->save();
        $this->returnFromIdp($state)->assertRedirect(route('account.security'))->assertSessionHas('error');

        $this->assertSame(0, OidcIdentity::count());
    }

    public function test_administrators_need_no_switch(): void
    {
        $this->idp->fake();
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->assertTrue($admin->mayUseOidc());
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/account/security')
            ->assertOk()->assertSee('Mit goettlichIT ID verknüpfen');
    }

    public function test_administrators_find_the_pocket_id_settings_in_the_card(): void
    {
        $confirmed = ['auth.password_confirmed_at' => time()];
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->withSession($confirmed)->get('/account/security')->assertOk()
            ->assertSee(route('settings.oidc'))->assertSee('Einstellungen für Pocket ID');
        $this->actingAs(User::factory()->create(['oidc_allowed' => true]))->withSession($confirmed)
            ->get('/account/security')->assertOk()->assertDontSee(route('settings.oidc'));

        // Not set up yet: the card is there for administrators, with the way to the settings.
        OidcSettings::disable('goettlichIT ID');
        $this->actingAs($admin)->withSession($confirmed)->get('/account/security')->assertOk()
            ->assertSee('Die Anmeldung mit goettlichIT ID ist noch nicht eingerichtet.')
            ->assertSee(route('settings.oidc'))
            ->assertDontSee(route('account.oidc.link'));
    }
}

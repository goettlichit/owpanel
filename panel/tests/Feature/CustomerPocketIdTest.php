<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\OidcIdentity;
use App\Models\Setting;
use App\Models\User;
use App\Oidc\OidcSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Support\OidcFake;
use Tests\TestCase;

/**
 * Pocket ID on the page "Access" of a customer (E-130, E-138).
 */
class CustomerPocketIdTest extends TestCase
{
    use RefreshDatabase;

    private const API = OidcFake::ISSUER.'/api';

    private User $admin;

    private Customer $customer;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        OidcSettings::save(OidcFake::ISSUER, OidcFake::CLIENT_ID, OidcFake::CLIENT_SECRET, 'goettlichIT ID');
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
        $this->customer = Customer::factory()->create(['first_name' => 'Anna', 'last_name' => 'Müller', 'email' => 'anna@example.com']);
        $this->account = User::factory()->create(['customer_id' => $this->customer->id, 'email' => 'anna@example.com', 'locale' => 'de']);
    }

    private function withKey(): void
    {
        Setting::put(Setting::POCKET_ID_API_KEY, Crypt::encryptString('scoped-test-key-0123456789'));
    }

    /**
     * @param  list<array<string, mixed>>  $users
     */
    private function fakeApi(array $users = []): void
    {
        Http::fake([
            self::API.'/users?*' => Http::response(['data' => $users, 'pagination' => ['totalItems' => count($users)]]),
            self::API.'/users/pid-1' => Http::response(['id' => 'pid-1', 'username' => 'anna', 'email' => 'anna@example.com', 'firstName' => 'Anna', 'lastName' => 'Müller']),
            self::API.'/users/pid-new/one-time-access-token' => Http::response(['token' => 'abcdefghijklmnop'], 201),
            self::API.'/users/pid-new/one-time-access-email' => Http::response(null, 204),
            self::API.'/users' => Http::response(['id' => 'pid-new', 'username' => 'anna@example.com', 'email' => 'anna@example.com', 'firstName' => 'Anna', 'lastName' => 'Müller'], 201),
        ]);
    }

    private function page(): string
    {
        return "/customers/{$this->customer->id}/access";
    }

    public function test_without_an_api_key_the_admin_only_allows_pocket_id(): void
    {
        $this->actingAs($this->admin)->get($this->page())->assertOk()
            ->assertSee('goettlichIT ID freischalten')->assertSee('Mit einem API-Schlüssel von Pocket ID');

        $this->actingAs($this->admin)->put("/customers/{$this->customer->id}/pocket-id/allow", ['allowed' => '1'])
            ->assertRedirect($this->page())->assertSessionHas('status', 'goettlichIT ID ist für den Kunden freigeschaltet.');
        $this->assertTrue($this->account->refresh()->oidc_allowed);
        $this->actingAs($this->admin)->get($this->page())->assertSee('Der Kunde verknüpft sein Konto selbst');

        $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/search")
            ->assertSessionHas('error', 'Es ist kein API-Schlüssel für Pocket ID eingetragen (System → Externe Dienste).');
    }

    public function test_a_customer_missing_in_pocket_id_is_created_and_linked_at_once(): void
    {
        $this->withKey();
        $this->fakeApi();

        $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/search")->assertRedirect($this->page());
        $this->actingAs($this->admin)->withSession(['pocket_id.search' => ['found' => null]])->get($this->page())
            ->assertSee('In goettlichIT ID gibt es kein Konto mit anna@example.com.')->assertSee('In goettlichIT ID anlegen und verknüpfen');

        $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/create")
            ->assertSessionHas('status', 'Der Kunde ist mit goettlichIT ID verknüpft.');

        $identity = OidcIdentity::sole();
        $this->assertSame([$this->account->id, OidcFake::ISSUER, 'pid-new'], [$identity->user_id, $identity->issuer, $identity->subject]);
        $this->assertTrue($this->account->refresh()->oidc_allowed);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === self::API.'/users'
            && $request['username'] === 'anna@example.com' && $request['isAdmin'] === false && $request['firstName'] === 'Anna'
            && $request->header('X-API-Key') === ['scoped-test-key-0123456789']);
        $this->assertSame(1, AuditLog::where('action', 'customer.pocket_id_created')->count());

        // A login link to set up the passkey, shown once.
        $response = $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/login-link");
        $this->assertSame(OidcFake::ISSUER.'/lc/abcdefghijklmnop', $response->getSession()->get('pocket_id.link'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/users/pid-new/one-time-access-token') && $request['ttl'] === 72 * 3600);

        $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/send-link")
            ->assertSessionHas('status', 'goettlichIT ID schickt dem Kunden den Anmeldelink.');
        $this->actingAs($this->admin)->get($this->page())->assertSee('Verknüpft mit dem Konto anna@example.com');
    }

    public function test_an_existing_account_is_linked_only_after_confirmation_and_with_the_same_address(): void
    {
        $this->withKey();
        $found = ['id' => 'pid-1', 'username' => 'anna', 'email' => 'Anna@Example.com', 'firstName' => 'Anna', 'lastName' => 'Müller'];
        $this->fakeApi([['id' => 'pid-9', 'username' => 'annabelle', 'email' => 'annabelle@example.com'], $found]);

        $response = $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/search");
        $this->assertSame('pid-1', $response->getSession()->get('pocket_id.search')['found']['id']);
        $this->assertSame(0, OidcIdentity::count(), 'searching links nothing');

        $this->actingAs($this->admin)->withSession(['pocket_id.search' => ['found' => $found]])->get($this->page())
            ->assertSee('Dieses Konto verknüpfen')->assertSee('anna · Anna@Example.com');

        $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/link", ['pocket_id_user' => 'pid-1'])
            ->assertSessionHas('status');
        $this->assertSame('pid-1', OidcIdentity::sole()->subject);

        // An account whose address is not the customer's is not linked.
        $this->account->forceFill(['email' => 'neu@example.com'])->save();
        $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/link", ['pocket_id_user' => 'pid-1'])
            ->assertSessionHas('error', 'Das Konto in Pocket ID hat inzwischen eine andere E-Mail-Adresse. Es wurde nicht verknüpft.');
    }

    public function test_an_account_linked_elsewhere_is_not_taken_and_unlinking_keeps_pocket_id(): void
    {
        $this->withKey();
        $this->fakeApi();
        $other = User::factory()->create();
        $other->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'pid-1', 'email' => 'anna@example.com']);

        $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/link", ['pocket_id_user' => 'pid-1'])
            ->assertSessionHas('error', 'Das Pocket-ID-Konto anna@example.com ist schon mit einem anderen Panel-Konto verknüpft.');
        $this->assertFalse($this->account->oidcIdentities()->exists());

        $other->oidcIdentities()->delete();
        $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/link", ['pocket_id_user' => 'pid-1']);
        $this->actingAs($this->admin)->delete("/customers/{$this->customer->id}/pocket-id")
            ->assertSessionHas('status', 'Die Verknüpfung mit goettlichIT ID ist gelöst. Das Konto dort bleibt.');
        $this->assertSame(0, OidcIdentity::count());
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_pocket_id_errors_are_shown_and_customers_cannot_use_any_of_it(): void
    {
        $this->withKey();
        Http::fake([self::API.'/*' => Http::response(['error' => 'missing scope'], 403)]);
        $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/pocket-id/search")
            ->assertSessionHas('error', 'Dem API-Schlüssel fehlen Rechte. Nötig sind users:read, users:write, users:login-codes.');

        foreach (['allow' => 'put', 'search' => 'post', 'link' => 'post', 'create' => 'post', 'login-link' => 'post', 'send-link' => 'post'] as $path => $method) {
            $this->actingAs($this->account)->{$method}("/customers/{$this->customer->id}/pocket-id/{$path}", ['allowed' => '1', 'pocket_id_user' => 'pid-1'])->assertForbidden();
        }
        $this->actingAs($this->account)->delete("/customers/{$this->customer->id}/pocket-id")->assertForbidden();
        $this->assertFalse($this->account->refresh()->oidc_allowed);
    }

    public function test_the_api_key_is_checked_before_it_is_stored(): void
    {
        Http::fake([self::API.'/users*' => Http::sequence()->push(null, 401)->push(['data' => []])]);
        $this->actingAs($this->admin)->put('/settings/pocket-id-api', ['pocket_id_api_key' => 'wrong-key-0123456789'])
            ->assertSessionHasErrors(['pocket_id_api_key' => 'Pocket ID lehnt den API-Schlüssel ab.']);
        $this->assertNull(Setting::get(Setting::POCKET_ID_API_KEY));

        $this->actingAs($this->admin)->put('/settings/pocket-id-api', ['pocket_id_api_key' => 'right-key-0123456789'])
            ->assertSessionHas('status', 'API-Schlüssel geprüft und gespeichert.');
        $this->assertSame('right-key-0123456789', Crypt::decryptString((string) Setting::get(Setting::POCKET_ID_API_KEY)));
        $this->actingAs($this->admin)->get('/settings/services')->assertOk()->assertSee('Pocket-ID-API')->assertSee('users:read, users:write, users:login-codes');
    }

    public function test_a_new_access_is_created_in_pocket_id_at_once_if_asked(): void
    {
        $this->withKey();
        $this->fakeApi();
        $new = Customer::factory()->create(['first_name' => 'Ben', 'last_name' => 'Kurz', 'email' => 'anna2@example.com']);
        $page = "/customers/{$new->id}/access";

        $this->actingAs($this->admin)->get($page)->assertOk()->assertSee('Auch in goettlichIT ID anlegen und Anmeldelink senden');

        $response = $this->actingAs($this->admin)->post($page, ['access_email' => 'anna2@example.com', 'method' => 'password', 'pocket_id' => '1'])
            ->assertRedirect($page)
            ->assertSessionHas('pocket_id.provisioned', 'Der Kunde ist in goettlichIT ID angelegt und verknüpft; goettlichIT ID schickt ihm einen Anmeldelink.');
        $this->assertIsString($response->getSession()->get('customer.access_password'));

        $user = $new->users()->sole();
        $this->assertTrue($user->oidc_allowed);
        $this->assertSame('pid-new', $user->oidcIdentities()->sole()->subject);
        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/users/pid-new/one-time-access-email');
        $this->assertSame(1, AuditLog::where('action', 'customer.pocket_id_created')->count());
        $this->assertSame(1, AuditLog::where('action', 'customer.pocket_id_link_sent')->count());
    }

    public function test_an_existing_pocket_id_user_is_not_linked_without_confirmation(): void
    {
        $this->withKey();
        $this->fakeApi([['id' => 'pid-1', 'username' => 'ben', 'email' => 'ben@example.com', 'firstName' => 'Ben', 'lastName' => 'Kurz']]);
        $new = Customer::factory()->create(['email' => 'ben@example.com']);

        $this->actingAs($this->admin)->post("/customers/{$new->id}/access", ['access_email' => 'ben@example.com', 'method' => 'password', 'pocket_id' => '1'])
            ->assertSessionHas('pocket_id.provision_error', true)
            ->assertSessionHas('pocket_id.provisioned', fn (string $text) => str_contains($text, 'In Pocket ID gibt es schon ein Konto mit dieser Adresse.'));

        $user = $new->users()->sole();
        $this->assertSame(0, $user->oidcIdentities()->count());
        $this->assertFalse((bool) $user->oidc_allowed);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_without_an_api_key_there_is_no_option_and_it_is_ignored(): void
    {
        Http::fake();
        $new = Customer::factory()->create(['email' => 'ben@example.com']);

        $this->actingAs($this->admin)->get("/customers/{$new->id}/access")->assertDontSee('Anmeldelink senden');
        $this->actingAs($this->admin)->post("/customers/{$new->id}/access", ['access_email' => 'ben@example.com', 'method' => 'password', 'pocket_id' => '1'])
            ->assertSessionMissing('pocket_id.provisioned');
        $this->assertSame(0, OidcIdentity::count());
        Http::assertNothingSent();
    }
}

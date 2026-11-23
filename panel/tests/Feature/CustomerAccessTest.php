<?php

namespace Tests\Feature;

use App\Customers\CustomerAccess;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Notifications\CustomerInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Access of a customer to the customer area (E-128) and its lock (E-127).
 */
class CustomerAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
        $this->customer = Customer::factory()->create(['email' => 'Anna@Example.com', 'locale' => 'de']);
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function mailOn(): void
    {
        Setting::put(Setting::MAIL_HOST, 'smtp.example.com');
        Setting::put(Setting::MAIL_FROM_ADDRESS, 'panel@example.com');
    }

    private function create(string $method, ?string $email = null): TestResponse
    {
        return $this->asAdmin()->post("/customers/{$this->customer->id}/access", [
            'access_email' => $email ?? $this->customer->email, 'method' => $method,
        ]);
    }

    public function test_an_admin_creates_an_access_with_a_start_password_shown_once(): void
    {
        $this->asAdmin()->get("/customers/{$this->customer->id}/access")->assertOk()
            ->assertSee('Zugang anlegen')->assertSee('value="Anna@Example.com"', false);

        $response = $this->create('password')->assertRedirect("/customers/{$this->customer->id}/access");
        $password = $response->getSession()->get('customer.access_password');
        $this->assertIsString($password);

        $user = $this->customer->users()->sole();
        $this->assertSame([Role::Customer, 'anna@example.com', $this->customer->first_name, 'de', true],
            [$user->role, $user->email, $user->first_name, $user->locale, $user->password_change_required]);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertSame(1, AuditLog::where('action', 'customer.access_created')->count());

        $this->actingAs($this->admin)->withSession(['customer.access_password' => $password])
            ->get("/customers/{$this->customer->id}/access")->assertSee($password)->assertSee('Start-Passwort');
        $this->actingAs($this->admin)->get("/customers/{$this->customer->id}/access")->assertDontSee($password);
    }

    public function test_without_outgoing_mail_there_is_no_invitation(): void
    {
        Notification::fake();
        $this->create('invite')->assertSessionHasErrors('method');
        $this->assertFalse($this->customer->users()->exists());
        $this->asAdmin()->get("/customers/{$this->customer->id}/access")->assertSee('Erst möglich, wenn der E-Mail-Versand eingerichtet ist');
        Notification::assertNothingSent();
    }

    public function test_the_invitation_link_sets_the_password_once_and_a_new_one_replaces_it(): void
    {
        $this->mailOn();
        Notification::fake();

        $this->create('invite')->assertSessionHas('status', 'Die Einladung ist unterwegs.');
        $user = $this->customer->users()->sole();
        $this->assertTrue($user->hasOpenInvitation());
        $this->assertFalse($user->password_change_required);

        $first = null;
        Notification::assertSentTo($user, CustomerInvitation::class, function (CustomerInvitation $notification) use (&$first) {
            $first = $notification->token;

            return $notification->locale === 'de';
        });
        $this->assertNotSame($first, $user->invitation_token, 'only the hash is stored');
        $this->asAdmin()->get("/customers/{$this->customer->id}/access")->assertSee('eingeladen')->assertSee('Einladung erneut senden');

        // A new invitation makes the old link useless.
        $this->asAdmin()->post("/customers/{$this->customer->id}/access/invite")->assertRedirect();
        $second = null;
        Notification::assertSentTo($user, CustomerInvitation::class, function (CustomerInvitation $notification) use (&$second, $first) {
            if ($notification->token !== $first) {
                $second = $notification->token;
            }

            return true;
        });
        $this->get("/invitation/{$user->id}/{$first}")->assertOk()->assertSee('ungültig, schon benutzt oder abgelaufen');

        $this->get("/invitation/{$user->id}/{$second}")->assertOk()->assertSee('Legen Sie ein Passwort fest')->assertSee('anna@example.com');
        $this->post("/invitation/{$user->id}/{$second}", ['password' => 'kurz', 'password_confirmation' => 'kurz'])->assertSessionHasErrors('password');
        $this->post("/invitation/{$user->id}/{$second}", ['password' => 'ein-langes-Passwort-2026', 'password_confirmation' => 'ein-langes-Passwort-2026'])
            ->assertRedirect('/login')->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('ein-langes-Passwort-2026', $user->password));
        $this->assertNull($user->invitation_token);
        $this->assertSame(1, AuditLog::where('action', 'customer.invitation_accepted')->count());

        // Used once: the link is gone.
        $this->get("/invitation/{$user->id}/{$second}")->assertSee('ungültig, schon benutzt oder abgelaufen');
        $this->post("/invitation/{$user->id}/{$second}", ['password' => 'noch-ein-Passwort-2026', 'password_confirmation' => 'noch-ein-Passwort-2026'])
            ->assertRedirect("/invitation/{$user->id}/{$second}");
        $this->assertTrue(Hash::check('ein-langes-Passwort-2026', $user->refresh()->password));
    }

    public function test_an_invitation_expires_after_72_hours(): void
    {
        $this->mailOn();
        Notification::fake();
        $this->create('invite');
        $user = $this->customer->users()->sole();
        $token = null;
        Notification::assertSentTo($user, CustomerInvitation::class, function (CustomerInvitation $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->travel(CustomerAccess::INVITATION_HOURS)->hours();
        $this->travel(1)->minutes();
        $this->get("/invitation/{$user->id}/{$token}")->assertSee('ungültig, schon benutzt oder abgelaufen');
        $this->asAdmin()->get("/customers/{$this->customer->id}/access")->assertSee('Einladung abgelaufen');
    }

    public function test_one_access_per_customer_with_a_free_address(): void
    {
        $this->create('password');
        $this->create('password', 'other@example.com')->assertForbidden();
        $this->assertSame(1, $this->customer->users()->count());

        // The address must be free among all accounts.
        $other = Customer::factory()->create();
        $this->asAdmin()->post("/customers/{$other->id}/access", ['access_email' => 'ANNA@example.com', 'method' => 'password'])
            ->assertSessionHasErrors('access_email');
    }

    public function test_an_admin_sets_a_new_start_password_that_replaces_password_and_invitation(): void
    {
        $this->mailOn();
        Notification::fake();
        $this->create('invite');
        $user = $this->customer->users()->sole();
        $user->forceFill(['password' => Hash::make('vergessen-2026')])->save();
        DB::table('sessions')->insert(['id' => 'offen', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->asAdmin()->get("/customers/{$this->customer->id}/access")->assertSee('Neues Start-Passwort');

        $response = $this->asAdmin()->post("/customers/{$this->customer->id}/access/password")
            ->assertRedirect("/customers/{$this->customer->id}/access");
        $password = $response->getSession()->get('customer.access_password');

        $user->refresh();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check('vergessen-2026', $user->password));
        $this->assertTrue($user->password_change_required);
        $this->assertNull($user->invitation_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(['email' => 'anna@example.com'], AuditLog::where('action', 'customer.password_reset')->sole()->context);
    }

    public function test_only_administrators_set_a_new_start_password(): void
    {
        $this->asAdmin()->post("/customers/{$this->customer->id}/access/password")->assertNotFound();

        $this->create('password');
        $own = $this->customer->users()->sole();
        $own->forceFill(['password_change_required' => false])->save();
        $before = $own->password;

        // Neither the customer himself nor another customer.
        $other = Customer::factory()->create();
        $stranger = User::factory()->create(['role' => Role::Customer, 'customer_id' => $other->id, 'password_change_required' => false]);
        foreach ([$own, $stranger] as $user) {
            $this->actingAs($user)->post("/customers/{$this->customer->id}/access/password")->assertForbidden();
        }
        $this->assertSame($before, $own->fresh()->password);
        $this->assertSame(0, AuditLog::where('action', 'customer.password_reset')->count());
    }

    public function test_a_locked_access_cannot_log_in_and_its_sessions_end(): void
    {
        $this->create('password');
        $user = $this->customer->users()->sole();
        $user->forceFill(['password' => Hash::make('richtiges-Passwort-2026'), 'password_change_required' => false])->save();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/access/lock")
            ->assertSessionHas('status', 'Der Zugang ist gesperrt; offene Sitzungen sind beendet.');
        $this->assertTrue($user->refresh()->isLocked());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

        // Locked shows only after the right password.
        $this->post('/logout');
        $this->post('/login', ['email' => 'anna@example.com', 'password' => 'falsch'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->post('/login', ['email' => 'anna@example.com', 'password' => 'richtiges-Passwort-2026'])
            ->assertSessionHasErrors('locked')
            ->assertSessionDoesntHaveErrors('email')
            ->assertSessionHas('error', 'Ihr Zugang ist gesperrt. Bitte wenden Sie sich an Ihren Anbieter.');
        $this->assertGuest();
        $this->get('/login')->assertOk()
            ->assertSeeInOrder(['alert alert-danger', 'Ihr Zugang ist gesperrt. Bitte wenden Sie sich an Ihren Anbieter.', 'name="email"'], false)
            ->assertDontSee('invalid-feedback', false);

        // A session that survived the lock ends on the next request.
        $this->actingAs($user)->get('/my/sites')->assertRedirect('/login');
        $this->assertGuest();

        $this->asAdmin()->post("/customers/{$this->customer->id}/access/unlock")->assertSessionHas('status', 'Der Zugang ist entsperrt.');
        $this->post('/logout');
        $this->post('/login', ['email' => 'anna@example.com', 'password' => 'richtiges-Passwort-2026'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['customer.access_locked', 'customer.access_unlocked'],
            AuditLog::whereIn('action', ['customer.access_locked', 'customer.access_unlocked'])->orderBy('created_at')->orderBy('id')->pluck('action')->all());
    }

    public function test_deleting_the_access_needs_its_address_and_a_customer_with_access_stays(): void
    {
        $this->create('password');
        $user = $this->customer->users()->sole();

        $this->asAdmin()->delete("/customers/{$this->customer->id}", ['confirm_number' => $this->customer->display_number])->assertForbidden();
        $this->asAdmin()->get("/customers/{$this->customer->id}/delete")
            ->assertSee('Der Kunde hat noch einen Zugang zum Kundenbereich.')->assertDontSee('Ein Kunde mit Webseiten');

        $this->asAdmin()->delete("/customers/{$this->customer->id}/access", ['confirm_email' => 'falsch@example.com'])
            ->assertSessionHas('error', 'Die E-Mail-Adresse stimmt nicht. Der Zugang bleibt.');
        $this->assertTrue($user->exists());

        $this->asAdmin()->delete("/customers/{$this->customer->id}/access", ['confirm_email' => 'anna@example.com'])
            ->assertSessionHas('status', 'Der Zugang ist gelöscht.');
        $this->assertFalse(User::whereKey($user->id)->exists());
        $this->asAdmin()->get("/customers/{$this->customer->id}/access")->assertSee('Zugang anlegen');
    }

    public function test_a_customer_lands_in_his_own_area_and_sees_only_his_websites(): void
    {
        $this->create('password');
        $user = $this->customer->users()->sole();
        $user->forceFill(['password_change_required' => false])->save();
        Site::factory()->create(['customer_id' => $this->customer->id, 'domain' => 'eigene.example']);
        Site::factory()->create(['customer_id' => Customer::factory()->create()->id, 'domain' => 'fremde.example']);
        Site::factory()->create(['customer_id' => null, 'domain' => 'betreiber.example']);

        $this->actingAs($user)->get('/')->assertRedirect('/my/sites');
        $this->actingAs($user)->get('/my/sites')->assertOk()
            ->assertSee('Meine Webseiten')->assertSee('eigene.example')
            ->assertDontSee('fremde.example')->assertDontSee('betreiber.example')
            ->assertDontSee(route('customers.index'));

        // Nothing of the administrator side, not even the own customer page.
        $this->actingAs($user)->get("/customers/{$this->customer->id}")->assertForbidden();
        foreach (['access', 'access/invite', 'access/lock', 'access/unlock'] as $path) {
            $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
                ->post("/customers/{$this->customer->id}/{$path}", ['access_email' => 'x@example.com', 'method' => 'password'])->assertForbidden();
        }
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->delete("/customers/{$this->customer->id}/access", ['confirm_email' => 'anna@example.com'])->assertForbidden();
        $this->assertFalse($user->refresh()->isLocked());

        $this->actingAs($this->admin)->get('/my/sites')->assertForbidden();
    }
}

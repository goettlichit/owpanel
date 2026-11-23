<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customers and the owner of a website (E-084).
 */
class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_an_admin_creates_a_customer_and_the_audit_log_keeps_only_the_id(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/customers')->assertOk()->assertSee('Noch keine Kunden.');

        $response = $this->actingAs($admin)->post('/customers', [
            'first_name' => ' Anna ', 'last_name' => 'Müller', 'company' => '  Bäckerei Müller ',
            'email' => 'Info@Baeckerei-Mueller.de', 'locale' => 'de', 'external_id' => 'kd1234',
        ]);
        $customer = Customer::sole();
        $response->assertRedirect("/customers/{$customer->id}")->assertSessionHas('status', 'Kunde Bäckerei Müller ist als K10001 angelegt.');

        $this->assertSame(['Anna', 'Müller', 'Bäckerei Müller'], [$customer->first_name, $customer->last_name, $customer->company]);
        $this->assertSame('Bäckerei Müller', $customer->display_name);
        $this->assertSame('K10001', $customer->display_number);
        $this->assertSame('info@baeckerei-mueller.de', $customer->email);
        $this->assertSame('kd1234', $customer->external_id);

        $entry = AuditLog::where('action', 'customer.created')->sole();
        $this->assertSame($customer->id, $entry->subject_id);
        $this->assertStringNotContainsString('Müller', json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('baeckerei', json_encode($entry->toArray()));
    }

    public function test_the_form_checks_its_input(): void
    {
        $admin = $this->admin();
        Customer::factory()->create(['external_id' => 'K-1']);

        $this->actingAs($admin)->post('/customers', ['first_name' => '', 'last_name' => '', 'email' => 'x', 'locale' => 'fr', 'external_id' => 'K-1'])
            ->assertSessionHasErrors(['first_name', 'last_name', 'email', 'locale', 'external_id']);
        $this->assertSame(1, Customer::count());
    }

    public function test_an_admin_edits_a_customer(): void
    {
        $customer = Customer::factory()->create(['external_id' => 'K-1']);

        $number = $customer->number;

        $this->actingAs($this->admin())->put("/customers/{$customer->id}", [
            'first_name' => 'Erika', 'last_name' => 'Muster', 'company' => '', 'email' => 'neu@example.de', 'locale' => 'en', 'external_id' => 'kd99',
            'number' => 1,
        ])->assertRedirect("/customers/{$customer->id}/details");

        $customer->refresh();
        $this->assertSame(['Erika Muster', null, 'neu@example.de', 'en', 'kd99'], [$customer->display_name, $customer->company, $customer->email, $customer->locale, $customer->external_id]);
        // The panel's number never changes.
        $this->assertSame($number, $customer->number);
    }

    public function test_customer_numbers_count_up_from_k10001(): void
    {
        $this->assertSame(['K10001', 'K10002', 'K10003'], Customer::factory()->count(3)->create()->pluck('display_number')->all());
    }

    public function test_websites_are_assigned_and_shown_on_both_sides(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create(['company' => 'Bäckerei Müller']);
        $site = Site::factory()->create(['domain' => 'baeckerei.example.de']);
        $other = Site::factory()->create(['domain' => 'zweite.example.de']);

        $this->actingAs($admin)->patch("/sites/{$site->id}/customer", ['customer' => $customer->id])
            ->assertRedirect("/sites/{$site->id}/domain")
            ->assertSessionHas('status', 'Die Webseite gehört jetzt zu Bäckerei Müller.');
        $this->actingAs($admin)->patch("/sites/{$other->id}/customer", ['customer' => $customer->id]);

        $this->assertSame($customer->id, $site->fresh()->customer_id);
        $this->actingAs($admin)->get("/customers/{$customer->id}")->assertOk()->assertSee('baeckerei.example.de')->assertSee('zweite.example.de');
        $this->actingAs($admin)->get('/customers')->assertSee('Bäckerei Müller');
        $this->actingAs($admin)->get('/sites')->assertSee('Bäckerei Müller');
        $this->actingAs($admin)->get("/sites/{$site->id}")->assertSee(route('customers.show', $customer), false);

        $entry = AuditLog::where('action', 'site.customer_assigned')->first();
        $this->assertSame(['domain' => 'baeckerei.example.de', 'customer_id' => $customer->id, 'owner' => 'customer'], $entry->context);

        // Back to the hoster.
        $this->actingAs($admin)->patch("/sites/{$site->id}/customer", ['customer' => ''])->assertSessionHas('status', 'Die Webseite ist jetzt nicht zugeordnet.');
        $this->assertNull($site->fresh()->customer_id);

        $this->actingAs($admin)->patch("/sites/{$site->id}/customer", ['customer' => 'not-a-customer'])->assertSessionHasErrors('customer');
    }

    public function test_a_new_website_can_get_a_customer_right_away(): void
    {
        $customer = Customer::factory()->create();
        Server::factory()->create();

        $this->actingAs($this->admin())->get('/sites/create?customer='.$customer->id)
            ->assertOk()
            ->assertSee('<option value="'.$customer->id.'" selected', false);
    }

    public function test_a_customer_with_websites_is_not_deleted(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        $site = Site::factory()->create();
        $site->forceFill(['customer_id' => $customer->id])->save();

        $this->actingAs($admin)->get("/customers/{$customer->id}/delete")->assertSee('Ein Kunde mit Webseiten lässt sich nicht löschen.');
        $this->actingAs($admin)->delete("/customers/{$customer->id}", ['confirm_number' => 'K10001'])->assertForbidden();

        $site->forceFill(['customer_id' => null])->save();
        $this->actingAs($admin)->delete("/customers/{$customer->id}", ['confirm_number' => 'K10002'])->assertSessionHas('error');
        $this->assertNotNull($customer->fresh());

        $this->actingAs($admin)->delete("/customers/{$customer->id}", ['confirm_number' => 'k10001'])->assertRedirect('/customers');
        $this->assertNull($customer->fresh());
        $this->assertSame(1, AuditLog::where('action', 'customer.deleted')->where('subject_id', $customer->id)->count());
    }

    public function test_customers_see_no_customers_and_cannot_assign_websites(): void
    {
        $own = Customer::factory()->create();
        $foreign = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $own->id]);
        $site = Site::factory()->create();
        $site->forceFill(['customer_id' => $own->id])->save();

        $this->actingAs($user)->get('/customers')->assertForbidden();
        $this->actingAs($user)->get("/customers/{$own->id}")->assertForbidden();
        $this->actingAs($user)->get("/customers/{$foreign->id}")->assertForbidden();
        $this->actingAs($user)->post('/customers', ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.de', 'locale' => 'de'])->assertForbidden();
        $this->actingAs($user)->put("/customers/{$foreign->id}", ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.de', 'locale' => 'de'])->assertForbidden();
        $this->actingAs($user)->delete("/customers/{$foreign->id}", ['confirm_number' => $foreign->display_number])->assertForbidden();
        $this->actingAs($user)->patch("/sites/{$site->id}/customer", ['customer' => $foreign->id])->assertForbidden();

        $this->assertSame($own->id, $site->fresh()->customer_id);
        $this->assertSame(2, Customer::count());
    }

    /**
     * A customer has his own left menu like a website: overview, master
     * data, package, access and delete, each on its own page.
     */
    public function test_the_customer_pages_have_their_own_menu_and_are_for_administrators_only(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'kunde.example']);

        $this->actingAs($admin)->get("/customers/{$customer->id}")->assertOk()
            ->assertSee('kunde.example')->assertSee('ow-stat', false)->assertSee('Kein Paket')
            ->assertSeeInOrder(['Übersicht', 'Stammdaten', 'Paket', 'Zugang', 'Kunde löschen', 'Alle Kunden']);

        $pages = ['details' => 'Vorname', 'package' => 'Es gibt noch keine Pakete.', 'access' => 'Zugang anlegen', 'delete' => 'Ein Kunde mit Webseiten lässt sich nicht löschen.'];
        foreach ($pages as $page => $text) {
            $this->actingAs($admin)->get("/customers/{$customer->id}/{$page}")->assertOk()->assertSee($text);
        }

        $user = User::factory()->create(['customer_id' => $customer->id]);
        foreach (['', '/details', '/package', '/access', '/delete'] as $page) {
            $this->actingAs($user)->get("/customers/{$customer->id}{$page}")->assertForbidden();
        }
    }
}

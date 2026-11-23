<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_the_audit_log(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        Audit::record('auth.login', user: $admin);

        $this->actingAs($admin)
            ->get('/audit-log')
            ->assertOk()
            ->assertSee('bg-green-lt">Angemeldet</span>', false)
            ->assertDontSee('auth.login')
            ->assertSee($admin->name);
    }

    public function test_customer_cannot_see_the_audit_log(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/audit-log')
            ->assertForbidden();
    }

    public function test_customer_does_not_see_the_audit_log_in_the_navigation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertDontSee('/audit-log');
    }

    public function test_guest_cannot_see_the_audit_log(): void
    {
        $this->get('/audit-log')->assertRedirect('/login');
    }

    public function test_entries_cannot_be_changed(): void
    {
        $entry = Audit::record('auth.login');

        $this->expectException(LogicException::class);
        $entry->update(['action' => 'something.else']);
    }

    public function test_entries_survive_when_the_user_is_deleted(): void
    {
        $user = User::factory()->create();
        Audit::record('auth.login', user: $user);

        $user->delete();

        $entry = AuditLog::sole();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertNull($entry->user);
    }

    public function test_each_entry_opens_with_all_its_details(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $site = Site::factory()->create(['domain' => 'audit.example.de']);
        $entry = Audit::record('site.dns_record_set', $site, ['host' => 'audit.example.de', 'type' => 'A', 'old' => '198.51.100.7', 'new' => '203.0.113.10', 'ttl' => 300], $admin);

        $this->actingAs($admin)->get(route('audit-log.index'))->assertOk()->assertSee(route('audit-log.show', $entry));
        $this->actingAs($admin)->get(route('audit-log.show', $entry))->assertOk()
            ->assertSee('site.dns_record_set')->assertSee('198.51.100.7')->assertSee('203.0.113.10')
            ->assertSee(route('sites.show', $site))->assertSee($admin->email);
        $this->actingAs($admin)->get('/audit-log/01JUNKNOWNENTRY0000000000')->assertNotFound();
    }

    public function test_entries_of_deleted_objects_have_no_link_and_customers_see_nothing(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $entry = Audit::record('site.deleted', null, ['domain' => 'gone.example.de'], $admin);
        DB::table('audit_logs')->where('id', $entry->id)->update(['subject_type' => 'Site', 'subject_id' => '01JGONE0000000000000000000']);

        $this->actingAs($admin)->get(route('audit-log.show', $entry))->assertOk()
            ->assertSee('gone.example.de')->assertDontSee(__('audit.show.open_subject'));

        $customer = User::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->actingAs($customer)->get(route('audit-log.show', $entry))->assertForbidden();
    }
}

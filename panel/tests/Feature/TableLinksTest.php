<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Entries in tables open through their name or number, there is no extra
 * button with an eye (E-131).
 */
class TableLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_open_entries_through_their_name_without_an_eye_button(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id]);
        $task = ServerTask::factory()->create(['server_id' => $site->server_id]);
        Audit::record('customer.updated', $customer, [], $admin);
        $entry = AuditLog::sole();

        $pages = [
            '/customers' => route('customers.show', $customer),
            "/customers/{$customer->id}" => route('sites.show', $site),
            "/servers/{$site->server_id}/tasks" => route('tasks.show', $task),
            '/audit-log' => route('audit-log.show', $entry),
        ];
        foreach ($pages as $page => $link) {
            $this->actingAs($admin)->get($page)->assertOk()
                ->assertSee('href="'.$link.'"', false)
                ->assertDontSee('ti-eye', false);
        }
    }
}

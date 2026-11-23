<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\ServerTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retention of personal data in the panel (E-092).
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $action, int $daysAgo): AuditLog
    {
        $entry = new AuditLog(['action' => $action, 'ip_address' => '203.0.113.5']);
        $entry->created_at = now()->subDays($daysAgo);
        $entry->save();

        return $entry;
    }

    public function test_failed_sign_ins_go_after_90_days_and_everything_after_a_year(): void
    {
        $oldFailure = $this->entry('auth.failed', 91);
        $recentFailure = $this->entry('auth.failed', 89);
        $oldLockout = $this->entry('auth.lockout', 100);
        $login = $this->entry('auth.login', 200);
        $oldLogin = $this->entry('auth.login', 366);
        $oldChange = $this->entry('site.created', 400);

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $this->assertNull($oldFailure->fresh());
        $this->assertNull($oldLockout->fresh());
        $this->assertNull($oldLogin->fresh());
        $this->assertNull($oldChange->fresh());
        $this->assertNotNull($recentFailure->fresh());
        $this->assertNotNull($login->fresh());
    }

    public function test_finished_tasks_go_after_90_days(): void
    {
        $old = ServerTask::factory()->create(['status' => TaskStatus::Succeeded, 'created_at' => now()->subDays(91)]);
        $oldFailed = ServerTask::factory()->create(['status' => TaskStatus::Failed, 'created_at' => now()->subDays(120)]);
        $recent = ServerTask::factory()->create(['status' => TaskStatus::Succeeded, 'created_at' => now()->subDays(30)]);
        $stuck = ServerTask::factory()->create(['status' => TaskStatus::Running, 'created_at' => now()->subDays(200)]);

        $this->artisan('model:prune', ['--model' => [ServerTask::class]])->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNull($oldFailed->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertNotNull($stuck->fresh());
    }

    public function test_stay_signed_in_lasts_30_days(): void
    {

        $user = User::factory()->create(['password' => 'secret-password-123']);
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'secret-password-123', 'remember' => '1']);

        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $cookie->getExpiresTime(), 120);
    }
}

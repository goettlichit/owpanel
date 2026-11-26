<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Jobs\CollectSiteStats;
use App\Models\Customer;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteVisit;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * Visitor statistics from the access logs (E-148).
 */
class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function answer(array $days): FakeScriptRunner
    {
        $runner = new FakeScriptRunner([json_encode(['ok' => true, 'script' => 'site-stats', 'days' => $days])."\n"]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    public function test_the_job_stores_the_days_and_asks_for_14_days_only_the_first_time(): void
    {
        $site = Site::factory()->create(['system_user' => 'ow38217']);
        $runner = $this->answer([
            ['date' => '2026-11-15', 'visitors' => 4, 'pageviews' => 9, 'pages' => [['/', 6], ['/kontakt', 3]]],
            ['date' => '2026-11-16', 'visitors' => 2, 'pageviews' => 3, 'pages' => [['/', 3]]],
            ['date' => 'kaputt', 'visitors' => 1, 'pageviews' => 1, 'pages' => []],
            ['date' => '2026-11-14', 'visitors' => -1, 'pageviews' => 1, 'pages' => []],
        ]);

        (new CollectSiteStats($site->server_id))->handle($runner);

        $this->assertSame(['--user', 'ow38217', '--days', '14'], $runner->calls[0]->arguments);
        $this->assertSame(2, SiteVisit::count());
        $day = $site->visits()->where('date', '2026-11-15')->sole();
        $this->assertSame([4, 9, [['/', 6], ['/kontakt', 3]]], [$day->visitors, $day->pageviews, $day->pages]);

        // The next hour counts today and yesterday again and replaces them.
        $runner = $this->answer([['date' => '2026-11-16', 'visitors' => 5, 'pageviews' => 8, 'pages' => []]]);
        (new CollectSiteStats($site->server_id))->handle($runner);
        $this->assertSame(['--user', 'ow38217', '--days', '2'], $runner->calls[0]->arguments);
        $this->assertSame([2, 5], [SiteVisit::count(), $site->visits()->where('date', '2026-11-16')->sole()->visitors]);
    }

    public function test_only_active_websites_are_counted_and_a_failure_changes_nothing(): void
    {
        $server = Server::factory()->create();
        Site::factory()->for($server)->create(['status' => SiteStatus::Pending]);
        $runner = $this->answer([]);
        (new CollectSiteStats($server->id))->handle($runner);
        $this->assertSame([], $runner->calls);

        $site = Site::factory()->for($server)->create();
        $this->app->instance(ScriptRunner::class, $runner = new FakeScriptRunner(['{"ok":false,"script":"site-stats","error":"stats_failed"}'."\n"], exitCode: 1));
        (new CollectSiteStats($server->id))->handle($runner);
        $this->assertSame(0, $site->visits()->count());
    }

    public function test_the_hourly_command_starts_one_job_per_server_with_websites(): void
    {
        Queue::fake();
        $with = Site::factory()->create()->server;
        Server::factory()->create();

        $this->artisan('owpanel:site-stats')->assertSuccessful();

        Queue::assertPushed(CollectSiteStats::class, 1);
        Queue::assertPushed(CollectSiteStats::class, fn (CollectSiteStats $job) => $job->serverId === $with->id);
    }

    public function test_the_page_shows_websites_chart_and_pages(): void
    {
        $this->travelTo(now('Europe/Berlin')->setDate(2026, 11, 16)->setTime(12, 0));
        config(['owpanel.timezone' => 'Europe/Berlin']);
        $shop = Site::factory()->create(['domain' => 'shop.example.de']);
        $blog = Site::factory()->create(['domain' => 'blog.example.de']);
        $shop->visits()->create(['date' => '2026-11-16', 'visitors' => 7, 'pageviews' => 21, 'pages' => [['/', 13], ['/produkte', 8]]]);
        $shop->visits()->create(['date' => '2026-11-10', 'visitors' => 3, 'pageviews' => 4, 'pages' => [['/produkte', 4]]]);
        $shop->visits()->create(['date' => '2026-09-01', 'visitors' => 100, 'pageviews' => 300, 'pages' => [['/alt', 300]]]);
        $blog->visits()->create(['date' => '2026-11-15', 'visitors' => 2, 'pageviews' => 2, 'pages' => [['/artikel', 2]]]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/system/statistics')->assertOk()
            ->assertSee('Statistik')
            ->assertSee('Zuletzt gezählt')
            // Most visited website first: 10 visitors in 30 days against 2.
            ->assertSeeInOrder(['shop.example.de', '7', '10', '10', '25', 'blog.example.de'])
            ->assertDontSee('Meistbesuchte Seiten');

        $this->actingAs($admin)->get("/system/statistics?site={$shop->id}&range=30")->assertOk()
            ->assertSeeInOrder(['Meistbesuchte Seiten', '/', '13', '/produkte', '12'])
            ->assertDontSee('/alt');

        $this->actingAs($admin)->get("/system/statistics?site={$shop->id}&range=months")->assertOk()
            ->assertSee('/alt')->assertSee('14 Monate');

        $this->actingAs($admin)->get('/system/statistics?range=bogus&site=nichts')->assertOk()->assertSee('Alle Webseiten');
    }

    public function test_the_links_change_range_and_website(): void
    {
        $shop = Site::factory()->create(['domain' => 'shop.example.de']);
        $shop->visits()->create(['date' => now()->toDateString(), 'visitors' => 1, 'pageviews' => 1, 'pages' => [['/', 1]]]);
        $admin = $this->admin();

        // From the default 30 days to the other ranges, without a website.
        $this->actingAs($admin)->get('/system/statistics')->assertOk()
            ->assertSee(e(route('statistics.index', ['range' => '7'])), false)
            ->assertSee(e(route('statistics.index', ['range' => '90'])), false)
            ->assertSee(e(route('statistics.index', ['range' => 'months'])), false)
            ->assertSee(e(route('statistics.index', ['site' => $shop->id, 'range' => '30'])), false);

        // With a website and 7 days, the ranges keep the website.
        $this->actingAs($admin)->get("/system/statistics?site={$shop->id}&range=7")->assertOk()
            ->assertSee(e(route('statistics.index', ['site' => $shop->id, 'range' => '90'])), false);
    }

    public function test_customers_do_not_see_the_statistics_yet(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);

        $this->actingAs($user)->get('/system/statistics')->assertForbidden();
    }

    public function test_numbers_are_kept_14_months(): void
    {
        $site = Site::factory()->create();
        $site->visits()->create(['date' => now()->subMonths(15)->toDateString(), 'visitors' => 1, 'pageviews' => 1, 'pages' => []]);
        $site->visits()->create(['date' => now()->subMonths(13)->toDateString(), 'visitors' => 1, 'pageviews' => 1, 'pages' => []]);

        $this->artisan('model:prune', ['--model' => [SiteVisit::class]])->assertSuccessful();

        $this->assertSame(1, SiteVisit::count());
        $site->delete();
        $this->assertSame(0, SiteVisit::count());
    }

    public function test_the_call_checks_its_input(): void
    {
        $this->assertSame(['--user', 'ow38217', '--days', '14'], ScriptCall::siteStats('ow38217', 14)->arguments);
        foreach ([['root', 2], ['ow38217', 0], ['ow38217', 15]] as [$user, $days]) {
            try {
                ScriptCall::siteStats($user, $days);
                $this->fail("{$user} {$days} accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

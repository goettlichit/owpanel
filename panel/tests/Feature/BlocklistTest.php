<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Livewire\BlocklistDetails;
use App\Models\Blocklist;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\User;
use App\Notify\AlertChecker;
use App\Notify\Notifier;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Support\PublicUrl;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Blocklists of a server's firewall (E-170).
 */
class BlocklistTest extends TestCase
{
    use RefreshDatabase;

    protected bool $renderDeferred = true;

    private const URL = 'https://lists.example.net/all.txt';

    private const LIST = "# example\n185.220.101.0/24\n45.155.205.17\n10.0.0.0/8\n2a0e:1d80::/32\n";

    /** @var object{calls: list<ScriptCall>, stats: string} */
    private object $runner;

    protected function setUp(): void
    {
        parent::setUp();
        PublicUrl::$resolver = fn (string $host) => match ($host) {
            'lists.example.net' => ['185.199.108.133'],
            'intern.example.net' => ['10.0.0.5'],
            'dual.example.net' => ['2a01:4f8:c17:2c::5', '185.199.108.140'],
            default => [],
        };
        $this->runner = new class implements ScriptRunner
        {
            /** @var list<ScriptCall> */
            public array $calls = [];

            /** Fields of the answer to server-blocklist --stats. */
            public string $stats = '"addresses":0,"packets":0,"bytes":0,"top":[]';

            public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult
            {
                $this->calls[] = $call;
                $output = $call->arguments === ['--stats']
                    ? '{"ok":true,"script":"server-blocklist",'.$this->stats.'}'."\n"
                    : '{"ok":true,"script":"'.$call->script->value.'"}'."\n";
                $onOutput($output);

                return ScriptResult::fromOutput($call->script->value, $output, 0, false);
            }
        };
        $this->app->instance(ScriptRunner::class, $this->runner);
    }

    protected function tearDown(): void
    {
        PublicUrl::$resolver = null;
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * @return list<ScriptCall>
     */
    private function blocklistCalls(): array
    {
        return array_values(array_filter($this->runner->calls, fn (ScriptCall $call) => $call->script === ServerScript::ServerBlocklist && $call->arguments === []));
    }

    public function test_a_list_is_fetched_checked_and_sent_with_the_exceptions(): void
    {
        Http::fake(['lists.example.net/*' => Http::response(self::LIST)]);
        $panel = Server::factory()->create(['host' => '127.0.0.1', 'public_ipv4' => '78.46.10.20', 'public_ipv6' => '2a01:4f8:c0c:1::1']);
        $panel->fail2banIgnores()->create(['address' => '93.184.216.0/24']);

        $this->actingAs($this->admin())->post("/servers/{$panel->id}/firewall/blocklists", ['name' => "  Angreifer\n DE ", 'url' => self::URL, 'interval_hours' => 6])
            ->assertRedirect("/servers/{$panel->id}/firewall")->assertSessionHas('status');

        $list = Blocklist::sole();
        $this->assertSame(3, $list->entries);
        $this->assertSame(1, $list->ignored);
        $this->assertSame(6, $list->interval_hours);
        $this->assertSame('Angreifer  DE', $list->name);
        $this->assertSame('Angreifer  DE', $list->label());
        $this->assertNotNull($list->checked_at);
        $this->assertSame(['185.220.101.0/24', '2a0e:1d80::/32', '45.155.205.17/32'], $list->cachedEntries());

        $calls = $this->blocklistCalls();
        $this->assertCount(1, $calls);
        $this->assertSame([], $calls[0]->arguments);
        $this->assertSame(
            "block\t185.220.101.0/24\nblock\t2a0e:1d80::/32\nblock\t45.155.205.17/32\nallow\t93.184.216.0/24\nallow\t78.46.10.20/32\nallow\t2a01:4f8:c0c:1::1/128\n",
            $calls[0]->stdin,
        );

        $this->get("/servers/{$panel->id}/firewall")->assertOk()
            ->assertSee('Angreifer  DE')
            ->assertSee('lists.example.net/all.txt')
            ->assertSee(__('firewall.blocklists.state_ok'));
    }

    public function test_wrong_addresses_and_formats_are_refused(): void
    {
        Http::fake([
            'lists.example.net/page' => Http::response("<html>\n<body>hello</body>\n</html>\n"),
            'lists.example.net/missing' => Http::response('nope', 404),
            'lists.example.net/moved' => Http::response('', 301, ['Location' => 'https://elsewhere.example.net/']),
        ]);
        $server = Server::factory()->create();
        $this->actingAs($this->admin());

        foreach ([
            'http://lists.example.net/all.txt' => 'https',
            'https://intern.example.net/all.txt' => 'internal',
            'https://lists.example.net/page' => 'not_a_list',
            'https://lists.example.net/missing' => 'http',
            'https://lists.example.net/moved' => 'redirect',
            'not a url' => 'format',
        ] as $url => $error) {
            $this->post("/servers/{$server->id}/firewall/blocklists", ['url' => $url, 'interval_hours' => 24])
                ->assertSessionHasErrors(['url' => Blocklist::describeError($error, $error === 'http' ? 'HTTP 404' : null)]);
        }
        $this->post("/servers/{$server->id}/firewall/blocklists", ['url' => self::URL, 'interval_hours' => 2])
            ->assertSessionHasErrors('interval_hours');

        $this->assertSame(0, Blocklist::count());
        $this->assertSame([], $this->blocklistCalls());
    }

    public function test_a_list_with_the_own_address_is_refused_unless_it_is_an_exception(): void
    {
        Http::fake(['lists.example.net/*' => Http::response(self::LIST)]);
        $server = Server::factory()->create();
        $this->actingAs($this->admin());

        $this->withServerVariables(['REMOTE_ADDR' => '185.220.101.9'])
            ->post("/servers/{$server->id}/firewall/blocklists", ['url' => self::URL, 'interval_hours' => 6])
            ->assertSessionHasErrors(['url' => __('firewall.blocklists.you_listed', ['ip' => '185.220.101.9', 'network' => '185.220.101.0/24'])]);
        $this->assertSame(0, Blocklist::count());

        $server->fail2banIgnores()->create(['address' => '185.220.101.9']);
        $this->withServerVariables(['REMOTE_ADDR' => '185.220.101.9'])
            ->post("/servers/{$server->id}/firewall/blocklists", ['url' => self::URL, 'interval_hours' => 6])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Blocklist::count());
    }

    public function test_the_scheduler_fetches_due_lists_and_sends_only_changes(): void
    {
        Http::fake(['lists.example.net/*' => Http::sequence()
            ->push(self::LIST)              // added
            ->push(self::LIST)              // hourly run: unchanged
            ->push("185.220.101.0/24\n")    // changed
            ->push('down', 503)]);          // failed
        $server = Server::factory()->create();
        $this->actingAs($this->admin())->post("/servers/{$server->id}/firewall/blocklists", ['url' => self::URL, 'interval_hours' => 1]);
        $list = Blocklist::sole();
        $this->assertCount(1, $this->blocklistCalls());

        // Not due yet.
        $this->artisan('owpanel:blocklists')->assertSuccessful();
        $this->assertCount(1, $this->blocklistCalls());

        $this->travel(61)->minutes();
        $this->artisan('owpanel:blocklists')->assertSuccessful();
        $this->assertCount(1, $this->blocklistCalls());

        $this->travel(61)->minutes();
        $this->artisan('owpanel:blocklists')->assertSuccessful();
        $this->assertCount(2, $this->blocklistCalls());
        $this->assertSame(1, $list->fresh()->entries);
        $this->assertSame("block\t185.220.101.0/24\n", $this->blocklistCalls()[1]->stdin);

        // A failure keeps the last good entries and is shown.
        $this->travel(61)->minutes();
        $this->artisan('owpanel:blocklists')->assertSuccessful();
        $this->assertCount(2, $this->blocklistCalls());
        $this->assertSame('http', $list->fresh()->error);
        $this->assertSame(1, $list->fresh()->failures);
        $this->assertSame(['185.220.101.0/24'], $list->fresh()->cachedEntries());
        $this->get("/servers/{$server->id}/firewall")
            ->assertSee(__('firewall.blocklists.state_failed', ['reason' => $list->fresh()->errorText()]));
        $this->assertSame('HTTP 503', $list->fresh()->error_detail);
    }

    public function test_interval_refresh_and_delete(): void
    {
        Http::fake(['lists.example.net/*' => Http::sequence()->push(self::LIST)->push(self::LIST)->push("185.220.101.0/24\n")]);
        $server = Server::factory()->create();
        $this->actingAs($this->admin())->post("/servers/{$server->id}/firewall/blocklists", ['url' => self::URL, 'interval_hours' => 6]);
        $list = Blocklist::sole();

        $this->assertNull($list->name);
        $this->assertSame('lists.example.net/all.txt', $list->label());
        $this->patch("/servers/{$server->id}/firewall/blocklists/{$list->id}", ['name' => 'Spamhaus', 'interval_hours' => 24])
            ->assertSessionHas('status', __('firewall.blocklists.saved'));
        $this->assertSame(24, $list->fresh()->interval_hours);
        $this->assertSame('Spamhaus', $list->fresh()->label());
        $this->patch("/servers/{$server->id}/firewall/blocklists/{$list->id}", ['name' => '', 'interval_hours' => 24]);
        $this->assertNull($list->fresh()->name);
        $this->patch("/servers/{$server->id}/firewall/blocklists/{$list->id}", ['name' => str_repeat('x', 61), 'interval_hours' => 24])
            ->assertSessionHasErrors('name');

        // A refresh by hand loads the lists even when unchanged, as a repair.
        $this->post("/servers/{$server->id}/firewall/blocklists/{$list->id}/refresh")
            ->assertSessionHas('status', __('firewall.blocklists.unchanged'));
        $this->assertCount(2, $this->blocklistCalls());
        $this->post("/servers/{$server->id}/firewall/blocklists/{$list->id}/refresh")
            ->assertSessionHas('status', __('firewall.blocklists.refreshed'));
        $this->assertCount(3, $this->blocklistCalls());

        $this->delete("/servers/{$server->id}/firewall/blocklists/{$list->id}")->assertSessionHas('status');
        $this->assertSame(0, Blocklist::count());
        $this->assertSame(0, DB::table('blocklist_contents')->count());
        $this->assertSame('', $this->blocklistCalls()[3]->stdin);
    }

    public function test_lists_of_another_server_and_customers_are_kept_out(): void
    {
        Http::fake(['lists.example.net/*' => Http::response(self::LIST)]);
        $server = Server::factory()->create();
        $other = Server::factory()->create();
        $admin = $this->admin();
        $this->actingAs($admin)->post("/servers/{$other->id}/firewall/blocklists", ['url' => self::URL, 'interval_hours' => 6]);
        $list = Blocklist::sole();

        $this->delete("/servers/{$server->id}/firewall/blocklists/{$list->id}")->assertNotFound();
        $this->post("/servers/{$server->id}/firewall/blocklists/{$list->id}/refresh")->assertNotFound();
        $this->patch("/servers/{$server->id}/firewall/blocklists/{$list->id}", ['interval_hours' => 1])->assertNotFound();

        $customer = User::factory()->create();
        $this->actingAs($customer)->post("/servers/{$other->id}/firewall/blocklists", ['url' => self::URL, 'interval_hours' => 6])->assertForbidden();
        $this->actingAs($customer)->delete("/servers/{$other->id}/firewall/blocklists/{$list->id}")->assertForbidden();
        $this->assertSame(1, Blocklist::count());
    }

    public function test_changing_the_fail2ban_exceptions_also_sends_the_blocklists(): void
    {
        Http::fake(['lists.example.net/*' => Http::response(self::LIST)]);
        $server = Server::factory()->create();
        $this->actingAs($this->admin())->post("/servers/{$server->id}/firewall/blocklists", ['url' => self::URL, 'interval_hours' => 6]);

        $this->post("/servers/{$server->id}/firewall/ignores", ['address' => '93.184.216.34']);
        $calls = $this->blocklistCalls();
        $this->assertCount(2, $calls);
        $this->assertStringContainsString("allow\t93.184.216.34/32\n", $calls[1]->stdin);
    }

    public function test_a_list_failing_twice_in_a_row_is_reported_until_it_works_again(): void
    {
        Http::fake(['lists.example.net/*' => Http::sequence()
            ->push(self::LIST)->push('down', 503)->push('down', 503)->push(self::LIST)]);
        $server = Server::factory()->create();
        $this->actingAs($this->admin())->post("/servers/{$server->id}/firewall/blocklists", ['url' => self::URL, 'interval_hours' => 1]);
        $list = Blocklist::sole();
        $key = "blocklist_failed:{$list->id}";

        $this->travel(61)->minutes();
        $this->artisan('owpanel:blocklists');
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen($key));

        $this->travel(61)->minutes();
        $this->artisan('owpanel:blocklists');
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen($key));

        $this->travel(61)->minutes();
        $this->artisan('owpanel:blocklists');
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen($key));
        $this->assertSame(0, $list->fresh()->failures);
    }

    public function test_the_other_address_is_tried_when_one_does_not_answer(): void
    {
        // IPv4 comes first and does not answer here; IPv6 does.
        Http::fake(function (Request $request, array $options) {
            if (str_contains(implode(' ', $options['curl'][CURLOPT_RESOLVE] ?? []), '185.199.108.140')) {
                throw new ConnectionException('cURL error 7: Failed to connect to dual.example.net port 443 after 3 ms: Could not connect to server (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://dual.example.net/list.txt');
            }

            return Http::response(self::LIST);
        });
        $server = Server::factory()->create();

        $this->actingAs($this->admin())->post("/servers/{$server->id}/firewall/blocklists", ['url' => 'https://dual.example.net/list.txt', 'interval_hours' => 6])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, Blocklist::sole()->entries);
        Http::assertSent(fn (Request $request) => str_starts_with($request->header('User-Agent')[0] ?? '', 'OWPanel/'));
    }

    public function test_the_reason_of_a_failed_connection_is_shown(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out after 5001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://lists.example.net/all.txt'));
        $server = Server::factory()->create();

        $this->actingAs($this->admin())->post("/servers/{$server->id}/firewall/blocklists", ['url' => self::URL, 'interval_hours' => 6])
            ->assertSessionHasErrors(['url' => 'Die Liste war nicht erreichbar (cURL error 28: Connection timed out after 5001 milliseconds).']);

        $this->get("/servers/{$server->id}/firewall")->assertOk()->assertSee(__('firewall.blocklists.empty'));
    }

    public function test_the_page_shows_which_addresses_were_refused_from_which_list(): void
    {
        Http::fake([
            'lists.example.net/all.txt' => Http::response(self::LIST),
            'lists.example.net/drop.txt' => Http::response("91.92.240.0/22\n185.220.101.0/24\n"),
        ]);
        $server = Server::factory()->create();
        $this->actingAs($this->admin());
        $this->post("/servers/{$server->id}/firewall/blocklists", ['name' => 'Angreifer', 'url' => self::URL, 'interval_hours' => 6]);
        $this->post("/servers/{$server->id}/firewall/blocklists", ['name' => 'Netze', 'url' => 'https://lists.example.net/drop.txt', 'interval_hours' => 24]);

        $this->runner->stats = '"addresses":3,"packets":120,"bytes":7200,"top":[["185.220.101.9",100,6000,30],["91.92.241.7",15,900,3600],["45.155.205.17",5,300,7200]]';
        $page = $this->get("/servers/{$server->id}/firewall")->assertOk();

        $page->assertSee(__('firewall.blocklists.hits.top'))
            ->assertSee('185.220.101.9')
            ->assertSee('Angreifer, Netze')
            ->assertSee('91.92.241.7')
            ->assertSee(__('firewall.blocklists.hits.summary', ['addresses' => '3', 'packets' => '120', 'bytes' => Units::bytes(7200)]))
            ->assertDontSee(__('firewall.blocklists.hits.partial'));

        $hits = Livewire::withoutLazyLoading()->test(BlocklistDetails::class, ['server' => $server])->viewData('blocklistHits');
        $lists = Blocklist::orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['packets' => 105, 'addresses' => 2], $hits['per_list'][$lists[0]->id]);
        $this->assertSame(['packets' => 115, 'addresses' => 2], $hits['per_list'][$lists[1]->id]);
        $this->assertSame(['Angreifer', 'Netze'], $hits['top'][0]['lists']);

        // Without any hits a short note.
        $this->runner->stats = '"addresses":0,"packets":0,"bytes":0,"top":[]';
        $this->get("/servers/{$server->id}/firewall")->assertSee(__('firewall.blocklists.hits.none'));

        // A server without hit sets yet (older layout) gets its lists again.
        $before = count($this->blocklistCalls());
        $this->runner->stats = '"addresses":0,"packets":0,"bytes":0,"top":[],"counting":false';
        $this->get("/servers/{$server->id}/firewall")->assertSee(__('firewall.blocklists.hits.setup'));
        $this->assertCount($before + 1, $this->blocklistCalls());

        // The reload after the task does not start the next one (no loop):
        // still not counting within ten minutes means a warning instead.
        ServerTask::query()->update(['status' => TaskStatus::Succeeded->value]);
        $this->get("/servers/{$server->id}/firewall")
            ->assertSee(__('firewall.blocklists.hits.unavailable'))
            ->assertDontSee(__('firewall.blocklists.hits.setup'));
        $this->assertCount($before + 1, $this->blocklistCalls());

        // After ten minutes it is tried once more.
        $this->travel(11)->minutes();
        $this->get("/servers/{$server->id}/firewall")->assertSee(__('firewall.blocklists.hits.setup'));
        $this->assertCount($before + 2, $this->blocklistCalls());
    }

    public function test_own_addresses_on_a_list_are_shown_and_an_address_can_be_checked(): void
    {
        Http::fake(['lists.example.net/*' => Http::response(self::LIST)]);
        $panel = Server::factory()->create(['name' => 'gw01', 'host' => '127.0.0.1', 'public_ipv4' => '185.220.101.20']);
        $web = Server::factory()->create(['name' => 'web01', 'public_ipv4' => '78.46.10.21', 'public_ipv6' => '2a0e:1d80:1::5']);
        $web->fail2banIgnores()->create(['address' => '45.155.205.17', 'comment' => 'Büro']);
        $this->actingAs($this->admin());
        $this->post("/servers/{$web->id}/firewall/blocklists", ['name' => 'Angreifer', 'url' => self::URL, 'interval_hours' => 6])
            ->assertSessionHasNoErrors();

        $page = $this->get("/servers/{$web->id}/firewall")->assertOk()
            ->assertSee(__('firewall.blocklists.own.title'));
        $own = collect(Livewire::withoutLazyLoading()->test(BlocklistDetails::class, ['server' => $web, 'clientIp' => '127.0.0.1'])->viewData('blocklistOwn'))->keyBy('address');
        $this->assertSame(['185.220.101.20', '2a0e:1d80:1::5', '45.155.205.17'], $own->keys()->sort()->values()->all());
        // The panel server and the exception are never blocked, web01 itself would be.
        $this->assertTrue($own['185.220.101.20']['excepted']);
        $this->assertSame('gw01', $own['185.220.101.20']['name']);
        $this->assertFalse($own['2a0e:1d80:1::5']['excepted']);
        $this->assertSame(['exception', 'Büro', true], [$own['45.155.205.17']['kind'], $own['45.155.205.17']['name'], $own['45.155.205.17']['excepted']]);
        $this->assertSame(['Angreifer'], $own['2a0e:1d80:1::5']['lists']);

        // Check one address: on a list, on none, or not an address.
        $this->get("/servers/{$web->id}/firewall?check=185.220.101.77")
            ->assertSee(__('firewall.blocklists.check.found', ['address' => '185.220.101.77', 'lists' => 'Angreifer']))
            ->assertSee(__('firewall.blocklists.own.blocked'));
        $this->get("/servers/{$web->id}/firewall?check=".urlencode('2a01:4f8::1'))
            ->assertSee(__('firewall.blocklists.check.none', ['address' => '2a01:4f8::1']));
        $this->get("/servers/{$web->id}/firewall?check=185.220.101.0/24")
            ->assertSee(__('firewall.blocklists.check.invalid'));
        $this->get("/servers/{$web->id}/firewall?check=nonsense")
            ->assertSee(__('firewall.blocklists.check.invalid'));

        // Another server without lists shows neither.
        $this->get("/servers/{$panel->id}/firewall")->assertOk()
            ->assertDontSee(__('firewall.blocklists.own.title'))
            ->assertDontSee(__('firewall.blocklists.check.title'));
    }
}

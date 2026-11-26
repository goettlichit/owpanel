<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Jobs\RunServerTask;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function fake(FakeScriptRunner $runner): FakeScriptRunner
    {
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    /**
     * Output of a successful site-create run as the script prints it.
     */
    private function createSucceeds(bool $tls = true): FakeScriptRunner
    {
        return $this->fake(new FakeScriptRunner([
            "Saving debug log to /var/log/letsencrypt/letsencrypt.log\n",
            '{"ok":true,"script":"site-create","domain":"shop.example.de","user":"ow12345","uid":12345,"php":"8.5","tls":'.($tls ? 'true' : 'false').'}'."\n",
        ]));
    }

    private function fails(string $script, string $error): FakeScriptRunner
    {
        return $this->fake(new FakeScriptRunner([
            "error: something went wrong\n",
            '{"ok":false,"script":"'.$script.'","error":"'.$error.'"}'."\n",
        ], exitCode: 1));
    }

    public function test_admin_sees_websites_in_the_navigation_and_an_empty_list(): void
    {
        $this->actingAs($this->admin())->get('/sites')
            ->assertOk()
            ->assertSee('href="'.url('/sites').'"', false)
            ->assertSee('Noch keine Webseiten.')
            ->assertSee('href="'.url('/sites/create').'"', false);
    }

    public function test_overview_offers_copy_buttons_for_domain_user_and_directories(): void
    {
        $site = Site::factory()->create(['domain' => 'copy.example.de']);

        $response = $this->actingAs($this->admin())->get(route('sites.show', $site))->assertOk();

        foreach (['copy.example.de', $site->system_user, $site->root_path.'/htdocs', $site->root_path.'/htdocs/public'] as $value) {
            $response->assertSee('data-ow-copy="'.e($value).'"', false);
        }
    }

    public function test_the_list_shows_websites_without_customer_first_then_the_others_alphabetically(): void
    {
        $server = Server::factory()->create();
        $customer = Customer::factory()->create();
        Site::factory()->for($server)->create(['domain' => 'zeta.example.de']);
        Site::factory()->for($server)->create(['domain' => 'beta.example.de', 'customer_id' => $customer->id]);
        Site::factory()->for($server)->create(['domain' => 'alpha.example.de']);
        Site::factory()->for($server)->create(['domain' => 'xn--bckerei-5wa.example.de', 'customer_id' => $customer->id]);

        $this->actingAs($this->admin())->get('/sites')->assertOk()
            ->assertSeeInOrder(['alpha.example.de', 'zeta.example.de', 'table-group-divider', 'bäckerei.example.de', 'beta.example.de'], false);
    }

    public function test_the_list_has_no_divider_with_one_group(): void
    {
        Site::factory()->for(Server::factory())->create(['domain' => 'alpha.example.de']);

        $this->actingAs($this->admin())->get('/sites')->assertOk()->assertDontSee('table-group-divider', false);
    }

    public function test_the_form_shows_the_dns_addresses_and_no_server_choice_for_one_server(): void
    {
        Server::factory()->create(['hosts_panel' => true, 'public_ipv4' => '203.0.113.10', 'public_ipv6' => '2001:db8::10']);

        $this->actingAs($this->admin())->get('/sites/create')
            ->assertOk()
            ->assertSee('A 203.0.113.10')
            ->assertSee('AAAA 2001:db8::10')
            ->assertSee('name="tls" value="1" checked', false)
            ->assertDontSee('name="server"', false);
    }

    public function test_creating_a_site_runs_site_create_and_activates_the_site(): void
    {
        $runner = $this->createSucceeds();
        $admin = $this->admin();
        $server = Server::factory()->create();

        $response = $this->actingAs($admin)->post('/sites', ['domain' => 'shop.example.de', 'tls' => '1']);

        $site = Site::sole();
        $task = ServerTask::sole();
        $response->assertRedirect("/sites/{$site->id}");

        $this->assertSame($server->id, $site->server_id);
        $this->assertSame('shop.example.de', $site->domain);
        $this->assertMatchesRegularExpression('/^ow[1-5]\d{4}$/', $site->system_user);
        $this->assertNotSame('ow10000', $site->system_user);
        $this->assertNull($site->customer_id);

        $this->assertSame($site->id, $task->site_id);
        $this->assertSame(ServerScript::SiteCreate, $task->script);
        $this->assertSame(
            ['--domain', 'shop.example.de', '--type', 'php', '--user', $site->system_user, '--tls', '--php', '8.5', '--php-memory', '256', '--php-upload', '64', '--php-time', '60', '--php-processes', '10', '--php-errors', 'off'],
            $runner->calls[0]->arguments,
        );

        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertTrue($site->tls);
        $this->assertSame('8.5', $site->php_version);

        $this->assertSame(['site.created', 'server.task_started'], AuditLog::orderBy('id')->pluck('action')->all());

        $this->actingAs($admin)->get("/tasks/{$task->id}")
            ->assertSee('Webseite anlegen')
            ->assertSee("/sites/{$site->id}");

        $this->actingAs($admin)->get('/sites')
            ->assertSee('shop.example.de')
            ->assertSee($site->system_user)
            ->assertSee('HTTP/3')
            ->assertSee('Aktiv');

        $this->actingAs($admin)->get("/sites/{$site->id}")
            ->assertOk()
            ->assertSee("/var/www/{$site->system_user}/htdocs/public")
            ->assertSee('https://shop.example.de')
            ->assertSee(route('sites.delete', $site), false)
            ->assertDontSee('Erneut anlegen');
    }

    public function test_the_web_request_only_queues_site_create(): void
    {
        Queue::fake();
        $runner = $this->createSucceeds();
        Server::factory()->create();

        $this->actingAs($this->admin())->post('/sites', ['domain' => 'shop.example.de', 'tls' => '1']);

        $this->assertSame([], $runner->calls);
        $this->assertSame(SiteStatus::Pending, Site::sole()->status);
        $this->assertSame(TaskStatus::Pending, ServerTask::sole()->status);
        Queue::assertPushed(RunServerTask::class);
    }

    public function test_a_site_without_certificate_omits_tls(): void
    {
        $runner = $this->createSucceeds(tls: false);
        Server::factory()->create();

        $this->actingAs($this->admin())->post('/sites', ['domain' => 'shop.example.de', 'tls' => '0']);

        $site = Site::sole();
        $this->assertNotContains('--tls', $runner->calls[0]->arguments);
        $this->assertFalse($site->wants_tls);
        $this->assertFalse($site->tls);

        $this->actingAs($this->admin())->get('/sites')->assertSee('Nur HTTP');
    }

    public function test_domains_are_normalised(): void
    {
        $this->fake(new FakeScriptRunner);
        Server::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sites', ['domain' => '  Shop.Example.DE ', 'tls' => '1']);
        $this->actingAs($admin)->post('/sites', ['domain' => 'müller.de', 'tls' => '1']);

        $this->assertSame(['shop.example.de', 'xn--mller-kva.de'], Site::orderBy('domain')->pluck('domain')->all());

        $site = Site::where('domain', 'xn--mller-kva.de')->sole();
        $this->assertSame('müller.de', $site->display_domain);
        $this->actingAs($admin)->get("/sites/{$site->id}")->assertSee('müller.de')->assertSee('xn--mller-kva.de');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDomains(): array
    {
        return [
            'scheme' => ['https://example.de'],
            'path' => ['example.de/shop'],
            'single label' => ['localhost'],
            'ip address' => ['192.0.2.1'],
            'leading hyphen' => ['-shop.example.de'],
            'empty label' => ['shop..example.de'],
            'trailing dot' => ['example.de.'],
            'underscore' => ['shop_1.example.de'],
            'newline' => ["example.de\nexample.com"],
            'shell' => ['example.de;reboot'],
            'option' => ['--tls'],
            'space' => ['shop example.de'],
            'empty' => [''],
            'too long label' => [str_repeat('a', 64).'.de'],
        ];
    }

    #[DataProvider('invalidDomains')]
    public function test_invalid_domains_are_rejected(string $domain): void
    {
        $runner = $this->fake(new FakeScriptRunner);
        Server::factory()->create();

        $this->actingAs($this->admin())->from('/sites/create')
            ->post('/sites', ['domain' => $domain, 'tls' => '1'])
            ->assertRedirect('/sites/create')
            ->assertSessionHasErrors('domain');

        $this->assertSame(0, Site::count());
        $this->assertSame([], $runner->calls);
    }

    public function test_a_domain_can_only_have_one_site_and_the_panel_domain_is_reserved(): void
    {
        config(['app.url' => 'https://panel.example.de']);
        $this->fake(new FakeScriptRunner);
        Site::factory()->create(['domain' => 'shop.example.de']);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sites', ['domain' => 'Shop.example.de', 'tls' => '1'])
            ->assertSessionHasErrors(['domain' => 'Für diese Domain gibt es schon eine Webseite.']);
        $this->actingAs($admin)->post('/sites', ['domain' => 'panel.example.de', 'tls' => '1'])
            ->assertSessionHasErrors(['domain' => 'Diese Domain gehört zum Panel selbst.']);

        $this->assertSame(1, Site::count());
    }

    public function test_with_several_servers_the_server_must_be_chosen(): void
    {
        $runner = $this->createSucceeds();
        Server::factory()->create(['name' => 'web1']);
        $web2 = Server::factory()->create(['name' => 'web2']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/sites/create')->assertSee('name="server"', false);

        $this->actingAs($admin)->post('/sites', ['domain' => 'shop.example.de', 'tls' => '1'])
            ->assertSessionHasErrors('server');
        $this->actingAs($admin)->post('/sites', ['domain' => 'shop.example.de', 'tls' => '1', 'server' => '01JAAAAAAAAAAAAAAAAAAAAAAA'])
            ->assertSessionHasErrors('server');
        $this->assertSame(0, Site::count());

        $this->actingAs($admin)->post('/sites', ['domain' => 'shop.example.de', 'tls' => '1', 'server' => $web2->id]);

        $this->assertSame($web2->id, Site::sole()->server_id);
        $this->assertSame($web2->id, ServerTask::sole()->server_id);
        $this->assertCount(1, $runner->calls);
    }

    public function test_without_a_server_no_site_can_be_created(): void
    {
        $this->actingAs($this->admin())->get('/sites/create')->assertSee('Es ist noch kein Server eingetragen.');
        $this->actingAs($this->admin())->post('/sites', ['domain' => 'shop.example.de'])
            ->assertSessionHas('error', 'Es ist noch kein Server eingetragen.');

        $this->assertSame(0, Site::count());
    }

    public function test_site_users_are_random_unique_and_never_the_panel(): void
    {
        $users = [];
        for ($i = 0; $i < 200; $i++) {
            $users[] = $user = Site::freeSystemUser();
            $number = (int) substr($user, 2);
            $this->assertGreaterThanOrEqual(10001, $number);
            $this->assertLessThanOrEqual(59999, $number);
        }

        Site::factory()->create(['system_user' => $users[0]]);
        $this->assertFalse(Site::where('system_user', Site::freeSystemUser())->exists());
    }

    public function test_a_failed_create_can_be_retried_with_the_same_parameters(): void
    {
        $this->fails('site-create', 'certificate_failed');
        $admin = $this->admin();
        Server::factory()->create();

        $this->actingAs($admin)->post('/sites', ['domain' => 'shop.example.de', 'tls' => '1']);

        $site = Site::sole();
        $this->assertSame(SiteStatus::Failed, $site->status);
        $this->assertFalse($site->tls);

        $this->actingAs($admin)->get("/sites/{$site->id}")
            ->assertSee('Das Anlegen ist fehlgeschlagen: Let’s Encrypt hat kein Zertifikat ausgestellt.', false)
            ->assertSee('Erneut anlegen')
            ->assertSee('alert-danger', false);

        $runner = $this->createSucceeds();
        $this->actingAs($admin)->post("/sites/{$site->id}/retry")
            ->assertRedirect("/sites/{$site->id}");

        $this->assertSame(
            ['--domain', 'shop.example.de', '--type', 'php', '--user', $site->system_user, '--tls', '--php', '8.5', '--php-memory', '256', '--php-upload', '64', '--php-time', '60', '--php-processes', '10', '--php-errors', 'off'],
            $runner->calls[0]->arguments,
        );
        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        $this->assertSame(2, $site->tasks()->count());
    }

    public function test_an_active_site_cannot_be_retried(): void
    {
        $runner = $this->fake(new FakeScriptRunner);
        $site = Site::factory()->create();

        $this->actingAs($this->admin())->post("/sites/{$site->id}/retry")
            ->assertSessionHas('error');

        $this->assertSame([], $runner->calls);
    }

    public function test_deleting_needs_the_domain_and_removes_the_site_afterwards(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create(['domain' => 'shop.example.de', 'system_user' => 'ow23456']);
        $runner = $this->fake(new FakeScriptRunner(['{"ok":true,"script":"site-delete","user":"ow23456"}'."\n"]));

        $this->actingAs($admin)->delete("/sites/{$site->id}", ['confirm_domain' => 'other.example.de'])
            ->assertSessionHasErrors(['confirm_domain' => 'Die Domain stimmt nicht mit der Webseite überein.']);
        $this->actingAs($admin)->delete("/sites/{$site->id}", [])
            ->assertSessionHasErrors('confirm_domain');
        $this->assertSame([], $runner->calls);

        $this->actingAs($admin)->delete("/sites/{$site->id}", ['confirm_domain' => ' Shop.Example.de '])
            ->assertRedirect('/sites');

        $this->assertSame(['--user', 'ow23456'], $runner->calls[0]->arguments);
        $this->assertSame(ServerScript::SiteDelete, $runner->calls[0]->script);
        $this->assertNull(Site::find($site->id));

        // The task and its log stay, the audit log keeps domain and user.
        $task = ServerTask::sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertNull($task->site_id);
        $entry = AuditLog::where('action', 'site.deleted')->sole();
        $this->assertSame('shop.example.de', $entry->context['domain']);
        $this->assertSame('ow23456', $entry->context['system_user']);
        $this->assertSame($admin->id, $entry->user_id);

        $this->actingAs($admin)->get("/tasks/{$task->id}")->assertOk()->assertSee('Webseite löschen');
        $this->actingAs($admin)->get("/sites/{$site->id}")->assertNotFound();
    }

    public function test_a_failed_delete_keeps_the_site(): void
    {
        $this->fails('site-delete', 'certificate_delete_failed');
        $admin = $this->admin();
        $site = Site::factory()->create(['domain' => 'shop.example.de']);

        $this->actingAs($admin)->delete("/sites/{$site->id}", ['confirm_domain' => 'shop.example.de']);

        $site->refresh();
        $this->assertSame(SiteStatus::Failed, $site->status);
        $this->actingAs($admin)->get("/sites/{$site->id}")
            ->assertSee('Das Löschen ist fehlgeschlagen: Certbot konnte das Zertifikat nicht löschen.')
            ->assertDontSee('Erneut anlegen')
            ->assertSee(route('sites.delete', $site), false);
    }

    public function test_only_one_task_runs_per_site(): void
    {
        Queue::fake();
        $admin = $this->admin();
        Server::factory()->create();

        $this->actingAs($admin)->post('/sites', ['domain' => 'shop.example.de', 'tls' => '1']);
        $site = Site::sole();

        $this->actingAs($admin)->get("/sites/{$site->id}")
            ->assertSee('Die Webseite wird gerade auf dem Server eingerichtet.');
        $this->actingAs($admin)->get("/sites/{$site->id}/delete")->assertRedirect("/sites/{$site->id}")->assertSessionHas('error');

        $this->actingAs($admin)->delete("/sites/{$site->id}", ['confirm_domain' => 'shop.example.de'])
            ->assertSessionHas('error', 'Für diese Webseite läuft bereits eine Aufgabe. Warte, bis sie beendet ist.');

        $this->assertSame(1, ServerTask::count());
        $this->assertSame(SiteStatus::Pending, $site->fresh()->status);
    }

    public function test_a_crashed_worker_marks_the_site_as_failed(): void
    {
        $site = Site::factory()->pending()->create();
        $task = ServerTask::factory()->running()->create([
            'server_id' => $site->server_id,
            'site_id' => $site->id,
            'script' => ServerScript::SiteCreate,
            'arguments' => ['--domain', $site->domain, '--type', 'php', '--user', $site->system_user],
        ]);

        (new RunServerTask($task->id))->failed(null);

        $this->assertSame(SiteStatus::Failed, $site->fresh()->status);
    }

    public function test_customers_cannot_see_create_or_delete_sites_not_even_their_own(): void
    {
        $runner = $this->fake(new FakeScriptRunner);
        $customer = Customer::factory()->create();
        $user = User::factory()->for($customer)->create();
        $own = Site::factory()->failed()->create();
        $own->forceFill(['customer_id' => $customer->id])->save();
        $foreign = Site::factory()->create();

        $this->actingAs($user)->get('/')->assertDontSee('href="'.url('/sites').'"', false);
        $this->actingAs($user)->get('/sites')->assertForbidden();
        $this->actingAs($user)->get('/sites/create')->assertForbidden();
        $this->actingAs($user)->post('/sites', ['domain' => 'new.example.de'])->assertForbidden();

        foreach ([$own, $foreign] as $site) {
            $this->actingAs($user)->get("/sites/{$site->id}")->assertForbidden();
            $this->actingAs($user)->post("/sites/{$site->id}/retry")->assertForbidden();
            $this->actingAs($user)->delete("/sites/{$site->id}", ['confirm_domain' => $site->domain])->assertForbidden();
        }

        $this->assertSame(2, Site::count());
        $this->assertSame(0, ServerTask::count());
        $this->assertSame([], $runner->calls);
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $site = Site::factory()->create();

        $this->get('/sites')->assertRedirect('/login');
        $this->get("/sites/{$site->id}")->assertRedirect('/login');
        $this->post('/sites', ['domain' => 'new.example.de'])->assertRedirect('/login');
        $this->delete("/sites/{$site->id}", ['confirm_domain' => $site->domain])->assertRedirect('/login');

        $this->assertSame(1, Site::count());
    }

    public function test_an_unknown_site_is_not_found(): void
    {
        $this->actingAs($this->admin())->get('/sites/01JAAAAAAAAAAAAAAAAAAAAAAA')->assertNotFound();
    }

    /**
     * A website has its own pages "Website and domain" and certificate, and
     * deleting is the last entry of its menu (E-137).
     */
    public function test_the_website_menu_leads_to_its_own_pages(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create(['domain' => 'owpanel.org']);

        $this->actingAs($admin)->get("/sites/{$site->id}")->assertOk()
            ->assertSeeInOrder(['Übersicht', 'Webseite und Domain', 'DNS', 'Zertifikat', 'Datenbanken', 'Cronjobs', 'SFTP', 'Backups', 'Aufgaben', 'Logs', 'Webseite löschen'])
            ->assertDontSee('Domain ändern')->assertDontSee('www-Adresse einschalten')->assertDontSee('Zuordnen');

        $this->actingAs($admin)->get("/sites/{$site->id}/domain")->assertOk()
            ->assertSeeInOrder(['Domain ändern', 'www-Adresse', __('sites.customer.text')]);
        $this->actingAs($admin)->get("/sites/{$site->id}/certificate")->assertOk()->assertSee('Gültig bis');

        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($user)->get("/sites/{$site->id}/domain")->assertForbidden();
    }
}

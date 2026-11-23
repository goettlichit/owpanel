<?php

namespace Tests\Feature;

use App\Databases\Transfers;
use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Livewire\TaskLog;
use App\Models\Database;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The way back from a task: a button in the header, and after a success
 * seen live an automatic return with "Stay here".
 */
class TaskBackTest extends TestCase
{
    use RefreshDatabase;

    private function task(ServerScript $script, array $attributes = []): ServerTask
    {
        return ServerTask::factory()->create(['script' => $script] + $attributes);
    }

    public function test_the_way_back_depends_on_the_script(): void
    {
        $server = Server::factory()->create(['name' => 'web1']);
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'shop.example.de']);
        $database = Database::factory()->create(['site_id' => $site->id]);

        $cases = [
            [ServerScript::SiteCreate, ['site_id' => $site->id], route('sites.show', $site), 'Zurück zu shop.example.de'],
            [ServerScript::SiteCreate, ['site_id' => null], route('sites.index'), 'Zurück zu den Webseiten'],
            [ServerScript::SiteDelete, ['site_id' => $site->id], route('sites.index'), 'Zurück zu den Webseiten'],
            [ServerScript::DbCreate, ['site_id' => $site->id, 'database_id' => $database->id], route('databases.show', $database), 'Zurück zu '.$database->name],
            [ServerScript::DbDelete, ['site_id' => $site->id, 'database_id' => null], route('databases.index', $site), 'Zurück zu den Datenbanken'],
            [ServerScript::ServerReboot, [], route('servers.updates', $server), 'Zurück zu den Updates'],
            [ServerScript::PanelTest, [], route('servers.show', $server), 'Zurück zu web1'],
        ];

        foreach ($cases as [$script, $attributes, $url, $label]) {
            $back = $this->task($script, ['server_id' => $server->id] + $attributes)->backTarget();
            $this->assertSame($url, $back['url'], $script->value);
            $this->assertSame($label, $back['label'], $script->value);
        }
    }

    public function test_the_task_page_has_a_back_button(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $site = Site::factory()->create(['domain' => 'shop.example.de']);
        $task = $this->task(ServerScript::SiteCreate, ['server_id' => $site->server_id, 'site_id' => $site->id]);

        $this->actingAs($admin)->get("/tasks/{$task->id}")
            ->assertOk()
            ->assertSee('href="'.route('sites.show', $site).'"', false)
            ->assertSee('Zurück zu shop.example.de')
            ->assertDontSee('tasks.details');
    }

    public function test_the_end_of_a_task_is_a_toast_and_the_page_stays(): void
    {
        // E-083: no countdown and no notice in the page; the blue button
        // in the header is the way back.
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $site = Site::factory()->create(['domain' => 'shop.example.de']);
        $task = ServerTask::factory()->running()->create(['server_id' => $site->server_id, 'site_id' => $site->id, 'script' => ServerScript::SiteCreate]);

        $component = Livewire::actingAs($admin)->test(TaskLog::class, ['task' => $task]);
        $task->forceFill(['status' => TaskStatus::Succeeded, 'finished_at' => now()])->save();

        $component->call('$refresh')
            ->assertDispatched('owpanel-toast', type: 'success', message: 'Webseite anlegen ist erfolgreich durchgelaufen.')
            ->assertDontSee('alert-success', false)
            ->assertDontSee('window.location', false);
    }

    public function test_a_finished_export_says_where_its_file_is(): void
    {
        config(['owpanel.transfers.path' => sys_get_temp_dir().'/owpanel-back-test']);
        Transfers::ensureDirectory();
        $file = Transfers::newName(true);
        file_put_contents(Transfers::path($file), 'x');

        $admin = User::factory()->admin()->withTwoFactor()->create();
        $database = Database::factory()->create();
        $task = ServerTask::factory()->running()->create([
            'server_id' => $database->server_id, 'site_id' => $database->site_id, 'database_id' => $database->id,
            'script' => ServerScript::DbExport, 'transfer_file' => $file,
        ]);

        $component = Livewire::actingAs($admin)->test(TaskLog::class, ['task' => $task]);
        $task->forceFill(['status' => TaskStatus::Succeeded, 'finished_at' => now()])->save();

        $component->call('$refresh')
            ->assertDispatched('owpanel-toast', type: 'success', message: 'Export ist fertig. Herunterladen unter Datenbank → Export/Import, 24 Stunden lang.')
            ->assertSee('Unter Export/Import von '.$database->name)
            ->assertSee(route('databases.transfer', $database))
            ->assertDontSee(route('tasks.download', $task));

        $this->assertSame(route('databases.transfer', $database), $task->backTarget()['url']);
        Transfers::remove($file);
    }
}

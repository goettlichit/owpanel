<?php

namespace Database\Factories;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerTask>
 */
class ServerTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'script' => ServerScript::PanelTest,
            'arguments' => ['--steps', '3'],
            'status' => TaskStatus::Pending,
        ];
    }

    public function running(string $output = ''): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Running,
            'output' => $output,
            'started_at' => now(),
        ]);
    }

    public function failed(string $error): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Failed,
            'error' => $error,
            'started_at' => now()->subSeconds(4),
            'finished_at' => now(),
        ]);
    }
}

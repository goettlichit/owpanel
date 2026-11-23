<?php

namespace App\Support;

use App\Models\Database;
use App\Models\Server;
use App\Models\Site;

/**
 * Which server tasks a page waits for (E-152): those of its website (also
 * on database pages), of its server, or else those the signed-in user
 * started, e.g. on a list after deleting a website.
 */
class TaskScope
{
    /**
     * @return array{0: string, 1: string|null}
     */
    public static function current(): array
    {
        $route = request()->route();
        $site = $route?->parameter('site');
        $database = $route?->parameter('database');
        $server = $route?->parameter('server');

        return match (true) {
            $site instanceof Site => ['site', $site->getKey()],
            $database instanceof Database && $database->site_id !== null => ['site', $database->site_id],
            $server instanceof Server => ['server', $server->getKey()],
            default => ['user', null],
        };
    }
}

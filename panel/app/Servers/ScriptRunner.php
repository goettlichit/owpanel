<?php

namespace App\Servers;

use App\Models\Server;

/**
 * Runs an approved script on a server. The real implementation uses SSH
 * (SshScriptRunner), tests use a fake.
 */
interface ScriptRunner
{
    /**
     * @param  callable(string): void  $onOutput  receives stdout and stderr as they arrive
     *
     * @throws ServerConnectionException when the server cannot be reached or
     *                                   is not the expected one
     */
    public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult;
}

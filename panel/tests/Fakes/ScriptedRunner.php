<?php

namespace Tests\Fakes;

use App\Models\Server;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;

/**
 * Answers each script with its own JSON result, for checks that run
 * several scripts in a row. Scripts without an answer fail.
 */
class ScriptedRunner implements ScriptRunner
{
    /** @var list<ScriptCall> */
    public array $calls = [];

    /**
     * @param  array<string, array<string, mixed>>  $answers  script name => result fields
     */
    public function __construct(public array $answers = []) {}

    public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult
    {
        $this->calls[] = $call;
        $script = $call->script->value;
        // A website runs as backend and gateway (E-195): without an own
        // answer the gateway answers like the backend step it follows.
        if ($script === 'gateway-site' && ! array_key_exists($script, $this->answers)) {
            foreach (['site-create', 'site-suspend', 'site-unsuspend', 'site-delete'] as $other) {
                if (array_key_exists($other, $this->answers)) {
                    $this->answers[$script] = $this->answers[$other];
                    break;
                }
            }
        }

        if (! array_key_exists($script, $this->answers)) {
            $line = json_encode(['ok' => false, 'script' => $script, 'error' => 'test_failed'])."\n";

            return ScriptResult::fromOutput($script, $line, 1);
        }

        $line = json_encode(['ok' => true, 'script' => $script] + $this->answers[$script])."\n";
        $onOutput($line);

        return ScriptResult::fromOutput($script, $line, 0);
    }

    /**
     * @return list<string>
     */
    public function scripts(): array
    {
        return array_map(fn (ScriptCall $call) => $call->script->value, $this->calls);
    }
}

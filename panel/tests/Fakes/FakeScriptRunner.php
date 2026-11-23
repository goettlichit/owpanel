<?php

namespace Tests\Fakes;

use App\Models\Server;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Servers\ServerConnectionException;

/**
 * Stands in for SSH in tests: replays the given output and ends with the
 * given exit code, or fails with a connection error.
 */
class FakeScriptRunner implements ScriptRunner
{
    /** @var list<ScriptCall> */
    public array $calls = [];

    /**
     * @param  list<string>  $chunks
     */
    public function __construct(
        public array $chunks = [],
        public ?int $exitCode = 0,
        public ?string $connectionError = null,
        public bool $timedOut = false,
        public ?string $stdout = null,
    ) {}

    /** @var list<string> contents of the stdin files, as sent */
    public array $stdinFiles = [];

    public static function succeeding(string $script = 'panel-test'): self
    {
        return new self([
            "running as root\n",
            "step 1 of 5\n",
            "warning: test message on stderr\n",
            '{"ok":true,"script":"'.$script.'","steps":5,"user":"root"}'."\n",
        ]);
    }

    public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult
    {
        $this->calls[] = $call;

        if ($this->connectionError !== null) {
            throw new ServerConnectionException($this->connectionError);
        }

        if ($call->stdinFile !== null) {
            $this->stdinFiles[] = (string) file_get_contents($call->stdinFile);
        }
        if ($call->stdoutFile !== null) {
            file_put_contents($call->stdoutFile, (string) $this->stdout);
        }

        foreach ($this->chunks as $chunk) {
            $onOutput($chunk);
        }

        return ScriptResult::fromOutput($call->script->value, implode('', $this->chunks), $this->exitCode, $this->timedOut);
    }
}

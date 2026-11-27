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

        $chunks = array_map(fn (string $chunk) => self::forPart($chunk, $call->script->value), $this->chunks);
        foreach ($chunks as $chunk) {
            $onOutput($chunk);
        }

        return ScriptResult::fromOutput($call->script->value, implode('', $chunks), $this->exitCode, $this->timedOut);
    }

    /**
     * A website runs as backend and gateway (E-195): an answer prepared for
     * one part of a website also answers the other parts of the same step,
     * so tests of a step need one fake only.
     */
    private static function forPart(string $chunk, string $script): string
    {
        $parts = [
            'gateway-site' => ['site-create', 'site-suspend', 'site-unsuspend', 'site-delete'],
            'site-create' => ['site-unsuspend'],
        ];
        foreach ($parts[$script] ?? [] as $other) {
            $chunk = str_replace('"script":"'.$other.'"', '"script":"'.$script.'"', $chunk);
        }

        return $chunk;
    }
}

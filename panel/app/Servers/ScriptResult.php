<?php

namespace App\Servers;

/**
 * Outcome of a script run. The panel reads only the JSON in the last output
 * line, never free text (docs/ARCHITEKTUR.md, "Schnittstelle der
 * Serverskripte").
 */
final readonly class ScriptResult
{
    /**
     * @param  array<string, mixed>|null  $json
     */
    public function __construct(
        public ?int $exitCode,
        public ?array $json,
        public bool $timedOut = false,
    ) {}

    /**
     * Builds the result from the end of the output: the last line that is a
     * JSON object with "ok" and the name of the expected script.
     *
     * The script prints it as its last line on stdout. Over SSH, stdout and
     * stderr travel on separate channels, so error messages written just
     * before the result may arrive after it. That is why the search goes
     * backwards instead of taking only the very last line. $depth allows
     * deeper answers such as the rows of db-query.
     */
    public static function fromOutput(string $script, string $outputTail, ?int $exitCode, bool $timedOut = false, int $depth = 8): self
    {
        foreach (array_reverse(preg_split('/\r?\n/', $outputTail)) as $line) {
            if (! str_starts_with($line, '{')) {
                continue;
            }

            $json = json_decode($line, true, $depth);
            if (is_array($json) && is_bool($json['ok'] ?? null) && ($json['script'] ?? null) === $script) {
                return new self($exitCode, $json, $timedOut);
            }
        }

        return new self($exitCode, null, $timedOut);
    }

    public function succeeded(): bool
    {
        return ! $this->timedOut && $this->exitCode === 0 && ($this->json['ok'] ?? false) === true;
    }

    /**
     * Error code for a failed run: the script's own code if it sent one,
     * otherwise a code of the panel.
     */
    public function error(): ?string
    {
        if ($this->succeeded()) {
            return null;
        }

        if ($this->timedOut) {
            return 'timeout';
        }

        $code = $this->json['error'] ?? null;
        if (is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code)) {
            return $code;
        }

        return $this->json === null ? 'invalid_result' : 'script_failed';
    }
}

<?php

namespace Tests\Unit;

use App\Servers\ScriptResult;
use PHPUnit\Framework\TestCase;

class ScriptResultTest extends TestCase
{
    public function test_reads_the_last_line_as_result(): void
    {
        $result = ScriptResult::fromOutput('panel-test', "step 1\n{\"ok\":true,\"script\":\"panel-test\",\"steps\":1}\n", 0);

        $this->assertTrue($result->succeeded());
        $this->assertNull($result->error());
        $this->assertSame(1, $result->json['steps']);
    }

    public function test_uses_the_error_code_of_the_script(): void
    {
        $result = ScriptResult::fromOutput('panel-test', "error: x\n{\"ok\":false,\"script\":\"panel-test\",\"error\":\"test_failed\"}\n", 1);

        $this->assertFalse($result->succeeded());
        $this->assertSame('test_failed', $result->error());
    }

    public function test_needs_exit_code_zero_and_ok(): void
    {
        $ok = '{"ok":true,"script":"panel-test"}';

        $this->assertFalse(ScriptResult::fromOutput('panel-test', $ok, 1)->succeeded());
        $this->assertSame('script_failed', ScriptResult::fromOutput('panel-test', $ok, 1)->error());
        $this->assertFalse(ScriptResult::fromOutput('panel-test', $ok, null)->succeeded());
        $this->assertSame('timeout', ScriptResult::fromOutput('panel-test', $ok, 0, timedOut: true)->error());
    }

    public function test_finds_the_result_when_stderr_arrives_after_it(): void
    {
        // Seen with real SSH: stderr travels on its own SSH channel.
        $output = "step 1 of 1\n{\"ok\":false,\"script\":\"panel-test\",\"error\":\"test_failed\"}\n"
            ."warning: test message on stderr\nerror: failure requested with --fail\n";

        $this->assertSame('test_failed', ScriptResult::fromOutput('panel-test', $output, 1)->error());
    }

    public function test_the_last_result_line_counts(): void
    {
        $output = "{\"ok\":true,\"script\":\"panel-test\"}\n{\"ok\":false,\"script\":\"panel-test\",\"error\":\"test_failed\"}\n";

        $this->assertSame('test_failed', ScriptResult::fromOutput('panel-test', $output, 0)->error());
    }

    public function test_rejects_results_of_another_script_or_in_another_form(): void
    {
        foreach ([
            '{"ok":true,"script":"site-create"}',
            ' {"ok":true,"script":"panel-test"}',
            '{"ok":"yes","script":"panel-test"}',
            '["ok"]',
            'sudo: a password is required',
            '',
        ] as $output) {
            $result = ScriptResult::fromOutput('panel-test', $output, 0);
            $this->assertFalse($result->succeeded(), $output);
            $this->assertSame('invalid_result', $result->error(), $output);
        }
    }

    public function test_ignores_error_codes_that_are_not_plain_codes(): void
    {
        $result = ScriptResult::fromOutput('panel-test', '{"ok":false,"script":"panel-test","error":"<b>x</b>"}', 1);

        $this->assertSame('script_failed', $result->error());
    }
}

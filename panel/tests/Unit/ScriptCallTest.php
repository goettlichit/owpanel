<?php

namespace Tests\Unit;

use App\Enums\ServerScript;
use App\Servers\ScriptCall;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ScriptCallTest extends TestCase
{
    public function test_builds_the_command_line_with_each_parameter_escaped(): void
    {
        $this->assertSame(
            "sudo -n '/opt/owpanel/bin/panel-test' '--steps' '3'",
            ScriptCall::panelTest()->commandLine(),
        );
        $this->assertSame(
            "sudo -n '/opt/owpanel/bin/panel-test' '--steps' '10' '--fail'",
            ScriptCall::panelTest(10, true)->commandLine(),
        );
    }

    public function test_shell_characters_stay_inside_one_parameter(): void
    {
        $call = new ScriptCall(ServerScript::SiteCreate, ['--domain', "example.de'; rm -rf / #", '--x', '$(id)']);

        $this->assertSame(
            "sudo -n '/opt/owpanel/bin/site-create' '--domain' 'example.de'\\''; rm -rf / #' '--x' '\$(id)'",
            $call->commandLine(),
        );
    }

    public function test_panel_test_accepts_one_to_ten_steps(): void
    {
        $this->assertSame(['--steps', '1'], ScriptCall::panelTest(1)->arguments);

        foreach ([0, 11, -1] as $steps) {
            try {
                ScriptCall::panelTest($steps);
                $this->fail("accepted {$steps} steps");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_control_characters_and_empty_parameters(): void
    {
        foreach (["a\nb", "a\0b", "a\rb", '', str_repeat('a', 256)] as $argument) {
            try {
                new ScriptCall(ServerScript::PanelTest, [$argument]);
                $this->fail('accepted '.json_encode($argument));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_named_arguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScriptCall(ServerScript::PanelTest, ['steps' => '3']);
    }

    public function test_site_create_passes_domain_type_user_and_tls(): void
    {
        $this->assertSame(
            "sudo -n '/opt/owpanel/bin/site-create' '--domain' 'shop.example.de' '--type' 'php' '--user' 'ow38217' '--tls'",
            ScriptCall::siteCreate('shop.example.de', 'ow38217', true)->commandLine(),
        );
        $this->assertSame(
            ['--domain', 'xn--mller-kva.de', '--type', 'php', '--user', 'ow10001'],
            ScriptCall::siteCreate('xn--mller-kva.de', 'ow10001', false)->arguments,
        );
        $this->assertSame(
            ['--domain', 'example.de', '--type', 'php', '--user', 'ow10001', '--existing'],
            ScriptCall::siteCreate('example.de', 'ow10001', false, existingOnly: true)->arguments,
        );
        $this->assertSame(
            ['--domain', 'example.de', '--type', 'php', '--user', 'ow10001', '--php', '8.6'],
            ScriptCall::siteCreate('example.de', 'ow10001', false, php: '8.6')->arguments,
        );
        $this->assertSame(
            ['--domain', 'example.de', '--type', 'php', '--user', 'ow10001', '--php-memory', '512', '--php-upload', '64', '--php-time', '60', '--php-processes', '4', '--php-errors', 'on'],
            ScriptCall::siteCreate('example.de', 'ow10001', false, settings: ['memory' => 512, 'upload' => 64, 'time' => 60, 'processes' => 4, 'errors' => true])->arguments,
        );
    }

    public function test_site_delete_passes_only_the_user(): void
    {
        $this->assertSame(
            "sudo -n '/opt/owpanel/bin/site-delete' '--user' 'ow59999'",
            ScriptCall::siteDelete('ow59999')->commandLine(),
        );
    }

    public function test_site_scripts_reject_the_panel_user_and_bad_input(): void
    {
        $calls = [
            fn () => ScriptCall::siteDelete('ow10000'),
            fn () => ScriptCall::siteDelete('ow60000'),
            fn () => ScriptCall::siteDelete('ow9999'),
            fn () => ScriptCall::siteDelete('root'),
            fn () => ScriptCall::siteDelete('ow12345 --x'),
            fn () => ScriptCall::siteCreate('example.de', 'ow10000', true),
            fn () => ScriptCall::siteCreate('Example.de', 'ow12345', true),
            fn () => ScriptCall::siteCreate('--tls', 'ow12345', true),
            fn () => ScriptCall::siteCreate('example.de;id', 'ow12345', true),
        ];

        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("accepted call {$i}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

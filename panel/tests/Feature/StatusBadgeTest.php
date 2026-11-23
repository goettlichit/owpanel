<?php

namespace Tests\Feature;

use Tests\TestCase;

class StatusBadgeTest extends TestCase
{
    public function test_status_is_shown_as_text_and_icon(): void
    {
        $html = (string) $this->blade('<x-status-badge status="failed" />');

        $this->assertStringContainsString('Fehlgeschlagen', $html);
        $this->assertStringContainsString('ti-circle-x', $html);
    }

    public function test_unknown_status_does_not_break_the_page(): void
    {
        $html = (string) $this->blade('<x-status-badge status="<script>" />');

        $this->assertStringContainsString('Unbekannt', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}

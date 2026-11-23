<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Test mail from System -> Settings (E-094), plain text.
 */
class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.test.subject'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.test');
    }
}

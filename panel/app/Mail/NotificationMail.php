<?php

namespace App\Mail;

use App\Notify\MessageText;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Alerts by mail (E-101), plain text. One message, or all messages from
 * the quiet hours in one mail.
 */
class NotificationMail extends Mailable
{
    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function __construct(public array $messages) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[OWPanel] '.MessageText::digestTitle($this->messages));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.notification');
    }
}

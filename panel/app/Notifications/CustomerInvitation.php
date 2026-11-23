<?php

namespace App\Notifications;

use App\Customers\CustomerAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation to the customer area (E-128) in the customer's language: a link
 * to set the first password, valid 72 hours and once.
 */
class CustomerInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.invitation.subject'))
            ->greeting(__('mail.invitation.greeting', ['name' => $notifiable->first_name]))
            ->line(__('mail.invitation.intro'))
            ->line(__('mail.invitation.email', ['email' => $notifiable->email]))
            ->action(__('mail.invitation.action'), route('invitation.show', ['user' => $notifiable->getKey(), 'token' => $this->token]))
            ->line(__('mail.invitation.expire', ['count' => CustomerAccess::INVITATION_HOURS]))
            ->line(__('mail.invitation.outro'))
            ->salutation(__('mail.reset.salutation'));
    }
}

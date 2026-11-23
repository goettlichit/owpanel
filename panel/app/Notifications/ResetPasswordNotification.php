<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Link to reset the password (E-094), in the language of the account. Sent
 * through the queue, so the answer to the request takes the same time
 * whether the address exists or not.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject(__('mail.reset.subject'))
            ->greeting(__('mail.reset.greeting', ['name' => $notifiable->first_name]))
            ->line(__('mail.reset.intro'))
            ->action(__('mail.reset.action'), $this->resetUrl($notifiable))
            ->line(__('mail.reset.expire', ['count' => $minutes]))
            ->line(__('mail.reset.outro'))
            ->salutation(__('mail.reset.salutation'));
    }
}

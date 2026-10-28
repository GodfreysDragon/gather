<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent instead of a confirmation link when someone registers with an address that
 * already has an account. Registration answers the same either way, so only the
 * address's owner learns that the account exists.
 */
class AccountAlreadyExists extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You already have a Gather account')
            ->greeting("Hi {$notifiable->name},")
            ->line('Someone, hopefully you, just tried to create a Gather account with this e-mail address. You already have one.')
            ->action('Log in', config('app.frontend_url').'/login')
            ->line('If this wasn\'t you, you can ignore this e-mail; your account hasn\'t changed.');
    }
}

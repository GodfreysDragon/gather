<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * "Confirm your address" for a new account. The link opens the SPA, which posts the
 * signed parameters to POST /api/v1/auth/email/verify/{id}/{hash} and, on success,
 * receives the account's first tokens.
 */
class VerifyEmailAddress extends Notification implements ShouldQueue
{
    use Queueable;

    public const EXPIRES_IN_MINUTES = 60;

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
            ->subject('Confirm your e-mail address')
            ->greeting("Hi {$notifiable->name},")
            ->line('Confirm your e-mail address to finish creating your Gather account.')
            ->action('Confirm my address', self::url($notifiable))
            ->line('The link works for '.self::EXPIRES_IN_MINUTES.' minutes. If you didn\'t sign up, you can ignore this e-mail.');
    }

    /**
     * The SPA page that completes the confirmation, carrying the API route's signed
     * parameters. The signature is relative, so it doesn't depend on the API's host.
     */
    public static function url(User $user): string
    {
        $signed = URL::temporarySignedRoute(
            'v1.auth.verification.verify',
            now()->addMinutes(self::EXPIRES_IN_MINUTES),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            absolute: false,
        );

        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return config('app.frontend_url').'/verify-email?'.http_build_query([
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }
}

<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password reset email whose button opens the Vue app (not a backend page).
 */
class ResetPasswordForSpa extends ResetPassword
{
    protected function resetUrl($notifiable)
    {
        return rtrim(config('app.frontend_url'), '/') . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    public function toMail($notifiable)
    {
        $minutes = (int) config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60);

        return (new MailMessage)
            ->subject('Reset your RevisionHub password')
            ->line('We received a request to reset the password for your RevisionHub account.')
            ->action('Reset password', $this->resetUrl($notifiable))
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not ask for this, you can ignore this email. Your password will not change.');
    }
}

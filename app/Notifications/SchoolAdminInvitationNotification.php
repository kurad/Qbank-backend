<?php

namespace App\Notifications;

use App\Models\SchoolAdminInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SchoolAdminInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected SchoolAdminInvitation $invitation,
        protected string $plainToken
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

   public function toMail(object $notifiable): MailMessage
{
    $frontendUrl = rtrim(config('app.frontend_url'),'/');

    $url = $frontendUrl . '/school-admin-invitation/' . urlencode($this->plainToken);

    $schoolName = $this->invitation->school?->school_name ?? 'your school';

    return (new MailMessage)
        ->subject('School Admin invitation - ' . $schoolName)
        ->greeting('You have been invited to join RevisionHub')
        ->line('You have been invited to become the School Administrator for ' . $schoolName . '.')
        ->line('As School Admin, you will be able to onboard teachers and manage your school\'s students, subjects and topics.')
        ->action('Accept School Admin invitation', $url)
        ->line('This invitation expires on ' . $this->invitation->expires_at->format('d M Y H:i') . '.')
        ->line('If you were not expecting this invitation, you can ignore this email.');
}
}

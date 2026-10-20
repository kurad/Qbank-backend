<?php

namespace App\Notifications;

use App\Models\TeacherInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected TeacherInvitation $invitation,
        protected string $plainToken
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'https://revisionhub.rw'), '/');
        $url = $frontendUrl . '/teacher-invitation/' . urlencode($this->plainToken);

        return (new MailMessage)
            ->subject('Teacher invitation to ' . ($this->invitation->school?->school_name ?? 'Revision Hub'))
            ->greeting('You have been invited to Revision Hub')
            ->line(($this->invitation->school?->school_name ?? 'A school') . ' has invited you to join as a teacher.')
            ->line('A teacher account can belong to one school at a time. If you already teach at another school in Revision Hub, accepting this invitation will ask you to confirm a transfer.')
            ->action('Open teacher invitation', $url)
            ->line('This invitation expires on ' . $this->invitation->expires_at->format('d M Y H:i') . '.');
    }
}

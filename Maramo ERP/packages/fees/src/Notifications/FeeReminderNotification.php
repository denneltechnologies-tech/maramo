<?php

namespace Classivo\Fees\Notifications;

use Classivo\Fees\Services\Fees\FeeReminderSender;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A fee reminder (guardian copy) or an escalation digest (office copy), sent
 * through Laravel's MAIL channel only. Subject and body are already rendered
 * by {@see FeeReminderSender}.
 */
class FeeReminderNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $lines */
    public function __construct(
        public string $subject,
        public array $lines,
        public ?string $greeting = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);

        if ($this->greeting !== null) {
            $mail->greeting($this->greeting);
        }

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['subject' => $this->subject, 'lines' => $this->lines];
    }
}

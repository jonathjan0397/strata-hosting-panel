<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupDnsSyncIssueNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    public function __construct(private readonly array $issues) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject('Strata DNS backup sync issue detected')
            ->greeting('DNS sync issue detected')
            ->line('Strata detected one or more secondary DNS sync issues that required self-heal or still need attention.');

        foreach ($this->issues as $issue) {
            $mail->line(sprintf(
                'Zone: %s | Node: %s | Result: %s | Details: %s',
                $issue['zone'] ?? 'unknown',
                $issue['node'] ?? 'unknown',
                $issue['result'] ?? 'failed',
                $issue['details'] ?? 'no details'
            ));
        }

        return $mail->line('Check the admin audit log and DNS backup sync output for the exact recovery path taken.');
    }
}

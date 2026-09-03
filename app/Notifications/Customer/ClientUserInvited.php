<?php

namespace App\Notifications\Customer;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientUserInvited extends Notification
{
    use Queueable;

    public function __construct(
        public string $token,
        public string $clientName,
        public string $invitedByName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Same URL shape Fortify uses for forgot-password — the route is
        // owned by Fortify (`password.reset`) and it already accepts the
        // token + email. Recipient lands on resources/views/pages/auth/
        // reset-password.blade.php to set their password, which triggers
        // App\Actions\Fortify\ResetUserPassword to forceFill the new hash.
        //
        // Pin host to the customer domain — staff trigger this notification
        // from the agents domain, but the reset-password Fortify route
        // lives on the customer surface (customer.php). Without this pin,
        // url() uses the current request's host (agents) and the link 404s.
        $path = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false);
        $url = 'https://'.config('domains.customer_domain').$path;

        return (new MailMessage)
            ->subject("You've been invited to {$this->clientName} on SiteWorks")
            ->greeting('Hi,')
            ->line("{$this->invitedByName} has invited you to join {$this->clientName} on SiteWorks.")
            ->line('Set a password to access your team account.')
            ->action('Set my password', $url)
            ->line("This link will expire in ".config('auth.passwords.users.expire')." minutes.")
            ->line("If you weren't expecting this invitation, you can safely ignore this email.");
    }
}

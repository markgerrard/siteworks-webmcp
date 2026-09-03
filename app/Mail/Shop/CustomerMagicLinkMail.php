<?php

namespace App\Mail\Shop;

use App\Models\Shop\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerMagicLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Customer $customer, public string $rawToken) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your sign-in link', to: [$this->customer->email]);
    }

    public function content(): Content
    {
        // The shop is served from the SITE's own public host, not the agents surface.
        // This used to be config('app.url') in the view, so the sign-in link pointed at
        // a host that does not serve that site's shop — it 404'd and the recipient had
        // no way to correct it.
        return new Content(
            view: 'mail.shop.magic-link',
            with: ['siteUrl' => 'https://'.$this->customer->site->publicHost()],
        );
    }
}

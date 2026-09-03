<?php

namespace App\Mail\Shop;

use App\Models\Shop\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DataExportReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Customer $customer, public string $signedUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your data export is ready', to: [$this->customer->email]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.shop.data-export-ready');
    }
}

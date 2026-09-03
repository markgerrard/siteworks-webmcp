<?php

namespace App\Mail\Shop;

use App\Models\Shop\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MerchantNewOrder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public string $merchantEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New order received — {$this->order->number}",
            to: [$this->merchantEmail],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.shop.merchant-new-order');
    }
}

<?php

namespace App\Mail\Shop;

use App\Models\Shop\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderReceipt extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Receipt for order {$this->order->number}",
            to: [$this->order->email],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.shop.order-receipt');
    }
}

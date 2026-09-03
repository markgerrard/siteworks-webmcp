<?php

namespace App\Mail;

use App\Models\SiteEnquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notification to the site owner when a visitor submits the quote /
 * contact form. reply-to is the enquirer so the owner can respond with
 * a plain reply.
 */
class SiteEnquiryReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SiteEnquiry $enquiry) {}

    public function envelope(): Envelope
    {
        $businessName = $this->enquiry->site->business_name ?? 'your website';

        return new Envelope(
            from: new Address(config('site.enquiry_from_address'), $businessName.' website'),
            subject: 'New enquiry from '.$this->enquiry->name.' — '.$businessName,
            replyTo: [new Address($this->enquiry->email, $this->enquiry->name)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.site-enquiry-received');
    }
}

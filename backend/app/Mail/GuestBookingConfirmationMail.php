<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuestBookingConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, string|int|null> $booking
     */
    public function __construct(
        public array $booking,
        public string $bookingUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your DMD Family Resort booking is confirmed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guest-booking-confirmation',
        );
    }
}

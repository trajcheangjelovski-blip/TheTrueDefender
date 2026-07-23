<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Queue\SerializesModels;

class DigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param Collection $posts */
    public function __construct(public Collection $posts, public string $unsubscribeUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Today on TheTrueDefender — Top Stories');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.digest');
    }
}

<?php

namespace App\Mail;

use App\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReplyNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Comment $reply,
        public Comment $parent,
        public string $postTitle,
        public string $postUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Someone replied to your comment on TheTrueDefender');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reply');
    }
}

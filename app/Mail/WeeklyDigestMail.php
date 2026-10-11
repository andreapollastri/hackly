<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(public array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) $this->report['subject']);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.weekly-digest',
            text: 'mail.weekly-digest-text',
        );
    }
}

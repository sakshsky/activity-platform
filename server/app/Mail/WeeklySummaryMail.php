<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WeeklySummaryMail extends Mailable
{
    public function __construct(public string $summaryBody, public string $weekLabel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Activity weekly summary: '.$this->weekLabel);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.weekly-summary');
    }
}

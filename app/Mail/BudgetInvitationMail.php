<?php

namespace App\Mail;

use App\Models\BudgetInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invites an email address to join a shared budget. The accept link carries the
 * plaintext token, which is never stored (only its hash is).
 */
class BudgetInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public BudgetInvitation $invitation,
        public string $acceptUrl,
        public bool $hasAccount,
    ) {}

    public function envelope(): Envelope
    {
        $inviter = $this->invitation->inviter?->name ?? 'Someone';

        return new Envelope(
            subject: "{$inviter} shared the budget \"{$this->invitation->budget->name}\" with you",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.budget-invitation',
            with: [
                'budgetName' => $this->invitation->budget->name,
                'inviterName' => $this->invitation->inviter?->name ?? 'A Budget Planner user',
                'role' => $this->invitation->role->value,
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}

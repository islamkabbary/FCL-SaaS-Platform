<?php

namespace App\Notifications;

use App\Models\SupportSession;
use Illuminate\Notifications\Notification;

/**
 * In-app notice to a company's Tenant Admins that platform support started a session
 * in their company. Stored in the tenant database; no external channel is required.
 * The current status is read from the central support session when displayed.
 */
class SupportAccessStarted extends Notification
{
    public function __construct(public SupportSession $session) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'support_session_id' => $this->session->id,
            'actor_name' => $this->session->actor_name,
            'actor_email' => $this->session->actor_email,
            'target_email' => $this->session->target_email,
            'reason' => $this->session->reason,
            'started_at' => $this->session->started_at->toIso8601String(),
            'expires_at' => $this->session->expires_at->toIso8601String(),
        ];
    }
}

<?php

namespace App\Impersonation;

use App\Enums\ImpersonationKind;
use App\Models\ImpersonationSession;
use App\Models\SupportSession;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * An impersonation verified for the current request by EnforceImpersonation.
 *
 * Kept on the request's attributes (never in a container singleton or static), so it
 * cannot leak into another request handled by the same long-lived process.
 */
final readonly class ActiveImpersonation
{
    public const RequestAttribute = 'impersonation';

    public function __construct(
        public SupportSession|ImpersonationSession $session,
    ) {}

    /**
     * The impersonation verified for the current request, if any.
     */
    public static function current(?Request $request = null): ?self
    {
        $request ??= app()->bound('request') ? app('request') : null;
        $active = $request?->attributes->get(self::RequestAttribute);

        return $active instanceof self ? $active : null;
    }

    public function kind(): ImpersonationKind
    {
        return $this->session->kind();
    }

    public function isSupport(): bool
    {
        return $this->kind() === ImpersonationKind::Support;
    }

    public function targetUserId(): int
    {
        return $this->session->targetUserId();
    }

    public function actorName(): string
    {
        return $this->session instanceof SupportSession ? $this->session->actor_name : $this->session->impersonator_name;
    }

    public function actorEmail(): string
    {
        return $this->session instanceof SupportSession ? $this->session->actor_email : $this->session->impersonator_email;
    }

    public function targetName(): string
    {
        return $this->session->target_name;
    }

    public function targetEmail(): string
    {
        return $this->session->target_email;
    }

    public function expiresAt(): CarbonInterface
    {
        return $this->session->expires_at;
    }

    public function recordDenied(string $action, ?string $httpMethod = null): void
    {
        $this->session->recordEvent('denied', $action, $httpMethod);
    }

    public function recordViewed(string $action): void
    {
        $this->session->recordEvent('viewed', $action, 'GET');
    }
}

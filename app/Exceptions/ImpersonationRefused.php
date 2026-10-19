<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An impersonation could not be started, or a support handoff could not be used.
 * The message is safe to show to the user; it never contains credentials.
 */
class ImpersonationRefused extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }

    public static function invalidHandoff(): self
    {
        return new self(__('This support access link is invalid, has already been used or has expired.'));
    }
}

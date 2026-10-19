<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a subscription, seat or billing operation does not apply to the current
 * state (e.g. renewing before the renewal window, approving a reviewed request,
 * overpaying a charge). The message is safe to show to the acting user.
 */
class InvalidSubscriptionChange extends RuntimeException {}

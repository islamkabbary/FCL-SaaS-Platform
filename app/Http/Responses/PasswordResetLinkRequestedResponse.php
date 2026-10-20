<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same answer to every password reset link request, whether a link was sent, the email
 * address is unknown in this company, or a link was requested for it moments ago. Fortify's
 * default responses tell those cases apart ("We can't find a user with that email address"),
 * which lets anyone find out who has an account.
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function toResponse($request): Response
    {
        $message = __('If an account exists for that email address, a password reset link has been sent to it.');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}

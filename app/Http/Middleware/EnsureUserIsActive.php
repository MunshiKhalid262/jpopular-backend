<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivation must take effect immediately, not at next login.
 *
 * A token issued before deactivation would otherwise stay valid for its whole
 * lifetime, so every authenticated request re-checks the flag and destroys the
 * offending tokens on the spot.
 *
 * Soft-deleted users are already rejected upstream: the SoftDeletes global scope
 * means Sanctum's token->tokenable resolves to null, producing a 401.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->canAuthenticate()) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $user->tokens()->delete();
            }

            return ApiResponse::error(
                message: 'This account has been deactivated.',
                status: Response::HTTP_UNAUTHORIZED,
                code: 'ACCOUNT_INACTIVE',
            );
        }

        return $next($request);
    }
}

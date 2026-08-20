<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\AuthenticateUser;
use App\Domain\Identity\Actions\ChangePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     *
     * The plain-text token is returned exactly once, here, so the Next.js BFF
     * can place it in an httpOnly cookie. It is never returned by any other
     * endpoint and never reaches browser JavaScript.
     */
    public function login(LoginRequest $request, AuthenticateUser $authenticate): JsonResponse
    {
        $result = $authenticate->handle(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            deviceName: $request->string('device_name')->toString(),
        );

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => (new AuthenticatedUserResource($result['user']))->resolve(),
        ]);
    }

    /**
     * POST /api/v1/auth/logout — revokes only the token that made this call.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::success(['message' => 'Logged out.']);
    }

    /**
     * GET /api/v1/auth/me — identity, roles, and effective permissions.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(new AuthenticatedUserResource($user));
    }

    /**
     * PUT /api/v1/auth/password
     */
    public function updatePassword(ChangePasswordRequest $request, ChangePassword $changePassword): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $changePassword->handle(
            user: $user,
            currentPassword: $request->string('current_password')->toString(),
            newPassword: $request->string('password')->toString(),
        );

        return ApiResponse::success([
            'message' => 'Password updated. Other sessions have been signed out.',
        ]);
    }
}

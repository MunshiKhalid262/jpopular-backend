<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Users;

use App\Domain\Identity\Actions\AssignRoles;
use App\Domain\Identity\Actions\CreateUser;
use App\Domain\Identity\Actions\SetUserStatus;
use App\Domain\Identity\Actions\UpdateUser;
use App\Domain\Identity\Services\AdminGuard;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AssignRolesRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Requests\Users\UpdateUserStatusRequest;
use App\Http\Resources\Users\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /api/v1/users
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles')
            ->when(
                $request->filled('search'),
                function ($query) use ($request): void {
                    $term = '%'.$request->string('search')->toString().'%';
                    $query->where(function ($q) use ($term): void {
                        $q->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term);
                    });
                }
            )
            ->when(
                $request->filled('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active'))
            )
            ->when(
                $request->filled('role'),
                fn ($query) => $query->role($request->string('role')->toString())
            )
            ->orderBy('name')
            ->paginate(perPage: min($request->integer('per_page', 25), 100));

        return ApiResponse::success(UserResource::collection($users));
    }

    /**
     * POST /api/v1/users
     */
    public function store(StoreUserRequest $request, CreateUser $createUser): JsonResponse
    {
        $data = $request->validated();

        $user = $createUser->handle(
            attributes: [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ],
            roles: $data['roles'],
        );

        return ApiResponse::success(new UserResource($user), status: 201);
    }

    /**
     * GET /api/v1/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return ApiResponse::success(new UserResource($user->load('roles')));
    }

    /**
     * PUT /api/v1/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): JsonResponse
    {
        $updated = $updateUser->handle($user, $request->validated());

        return ApiResponse::success(new UserResource($updated));
    }

    /**
     * PUT /api/v1/users/{user}/status
     *
     * @throws BusinessRuleException
     */
    public function updateStatus(
        UpdateUserStatusRequest $request,
        User $user,
        SetUserStatus $setUserStatus,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $setUserStatus->handle(
            user: $user,
            isActive: $request->boolean('is_active'),
            actor: $actor,
        );

        return ApiResponse::success(new UserResource($updated));
    }

    /**
     * PUT /api/v1/users/{user}/roles
     *
     * @throws BusinessRuleException
     */
    public function updateRoles(AssignRolesRequest $request, User $user, AssignRoles $assignRoles): JsonResponse
    {
        /** @var list<string> $roles */
        $roles = $request->validated()['roles'];

        $updated = $assignRoles->handle($user, $roles);

        return ApiResponse::success(new UserResource($updated));
    }

    /**
     * DELETE /api/v1/users/{user} — soft delete, per the architecture's
     * soft-delete strategy for users.
     *
     * @throws BusinessRuleException
     */
    public function destroy(Request $request, User $user, AdminGuard $adminGuard): JsonResponse
    {
        $this->authorize('delete', $user);

        /** @var User $actor */
        $actor = $request->user();

        if ($user->is($actor)) {
            throw BusinessRuleException::cannotDeleteSelf();
        }

        $adminGuard->assertCanBeDeleted($user);

        $user->tokens()->delete();
        $user->delete();

        return ApiResponse::success(['message' => 'User deleted.']);
    }
}

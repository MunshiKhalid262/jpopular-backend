<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Pinned explicitly so permission checks are deterministic.
     *
     * Sanctum authenticates against the `users` provider, which both the `web`
     * and `sanctum` guards share. Without this, spatie has to infer a guard and
     * can resolve either one, causing permission lookups to miss. Permissions
     * are therefore seeded with guard_name = 'web' and checked against it.
     */
    protected string $guard_name = 'web';

    /**
     * Explicit allow-list. Never $guarded = [].
     *
     * `is_active`, `last_login_at`, and `email_verified_at` are deliberately
     * absent: they are set by Actions, never by client input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * A user who may currently authenticate: active and not soft-deleted.
     * (The SoftDeletes global scope already excludes trashed rows.)
     */
    public function canAuthenticate(): bool
    {
        return $this->is_active === true && $this->deleted_at === null;
    }
}

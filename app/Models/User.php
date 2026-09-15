<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['telegram_user_id', 'telegram_username', 'role', 'name', 'phone', 'is_active'])]
class User extends Model
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function master(): HasOne
    {
        return $this->hasOne(Master::class);
    }

    public function isSuperadmin(): bool
    {
        return $this->role === UserRole::SUPERADMIN;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function isMaster(): bool
    {
        return $this->role === UserRole::MASTER;
    }
}

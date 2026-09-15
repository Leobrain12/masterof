<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingInput extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'kind', 'payload', 'expires_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, $key, $default);
    }

    public function put(string $key, mixed $value): void
    {
        $payload = $this->payload;
        data_set($payload, $key, $value);
        $this->payload = $payload;
    }
}

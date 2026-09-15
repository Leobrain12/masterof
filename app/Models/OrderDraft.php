<?php

namespace App\Models;

use App\Enums\OrderDraftStep;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDraft extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['created_by_user_id', 'step', 'payload', 'expires_at'];

    protected function casts(): array
    {
        return [
            'step' => OrderDraftStep::class,
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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

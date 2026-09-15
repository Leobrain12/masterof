<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingInput extends Model
{
    use HasUuids, Prunable;

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

    /**
     * Истёкший pending_input уже не читается ни одним flow (см. isExpired())
     * и не может быть перезапущен — держать его дальше нет смысла. Модель
     * подхватывается автообнаружением `model:prune` (см. routes/console.php).
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
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

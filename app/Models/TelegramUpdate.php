<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class TelegramUpdate extends Model
{
    use Prunable;

    /**
     * Telegram повторно доставляет update при сбое ретраями в пределах часов,
     * не дней — 7 дней с большим запасом. Дольше держать нечего: запись нужна
     * только чтобы поймать повторную доставку одного и того же update_id.
     */
    private const RETENTION_DAYS = 7;

    protected $primaryKey = 'update_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = ['update_id', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    /**
     * Атомарно отмечает апдейт обработанным. true — впервые видим этот update_id
     * (можно обрабатывать), false — уже был обработан (гонка или повторная
     * доставка от Telegram, ТЗ п.90) — вызывающий код должен просто выйти.
     */
    public static function claim(int $updateId): bool
    {
        return self::query()->insertOrIgnore(['update_id' => $updateId, 'processed_at' => now()]) === 1;
    }

    /**
     * Модель подхватывается автообнаружением `model:prune` (см. routes/console.php).
     */
    public function prunable(): Builder
    {
        return static::query()->where('processed_at', '<=', now()->subDays(self::RETENTION_DAYS));
    }
}

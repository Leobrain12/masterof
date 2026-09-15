<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUpdate extends Model
{
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
}

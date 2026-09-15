<?php

namespace App\Models;

use App\Enums\MediaStage;
use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OrderMedia extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'order_id', 'visit_id', 'work_report_id', 'uploaded_by_user_id',
        'media_type', 'stage', 'telegram_file_id', 'telegram_file_unique_id',
        'mime_type', 'original_file_name', 'file_size', 'duration_seconds',
        'width', 'height', 'storage_path', 'caption',
    ];

    protected function casts(): array
    {
        return [
            'media_type' => MediaType::class,
            'stage' => MediaStage::class,
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(OrderVisit::class, 'visit_id');
    }

    public function workReport(): BelongsTo
    {
        return $this->belongsTo(WorkReport::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * ТЗ п.54: доступ только через signed URL/authenticated backend, не публичный bucket.
     * На локальном диске temporaryUrl() недоступен (ограничение локального адаптера) —
     * тогда просмотр идёт через переотправку в Telegram по telegram_file_id
     * (см. App\Services\Orders\MediaViewer), не через эту ссылку.
     */
    public function signedUrl(int $minutes = 30): ?string
    {
        if (! $this->storage_path) {
            return null;
        }

        $disk = Storage::disk('media');

        return method_exists($disk, 'temporaryUrl')
            ? $disk->temporaryUrl($this->storage_path, now()->addMinutes($minutes))
            : null;
    }
}

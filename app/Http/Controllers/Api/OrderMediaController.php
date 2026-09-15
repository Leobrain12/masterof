<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaStage;
use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ТЗ п.88: POST /api/v1/orders/{id}/media — тот же смысл, что у MediaCollector
 * в боте (ТЗ п.47, 52-55), но источник файла другой: не telegram_file_id,
 * а обычная multipart-загрузка от CRM. telegram_file_id/uploaded_by_user_id
 * поэтому nullable (см. миграцию 2026_09_15_120500).
 */
class OrderMediaController extends Controller
{
    private const MAX_FILE_KB = 20 * 1024;

    public function __invoke(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_FILE_KB, 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime'],
            'stage' => ['required', Rule::in(array_column(MediaStage::cases(), 'value'))],
            'caption' => ['nullable', 'string', 'max:1024'],
        ]);

        $file = $data['file'];
        $type = str_starts_with((string) $file->getMimeType(), 'video/') ? MediaType::VIDEO : MediaType::PHOTO;

        $path = "orders/{$order->id}/".Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        Storage::disk('media')->put($path, file_get_contents($file->getRealPath()));

        $media = OrderMedia::query()->create([
            'order_id' => $order->id,
            'uploaded_by_user_id' => null,
            'media_type' => $type,
            'stage' => $data['stage'],
            'telegram_file_id' => null,
            'mime_type' => $file->getMimeType(),
            'original_file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'storage_path' => $path,
            'caption' => $data['caption'] ?? null,
        ]);

        return response()->json([
            'id' => $media->id,
            'order_id' => $order->id,
            'media_type' => $media->media_type->value,
            'stage' => $media->stage->value,
            'original_file_name' => $media->original_file_name,
            'file_size' => $media->file_size,
        ], 201);
    }
}

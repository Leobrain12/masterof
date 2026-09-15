<?php

namespace App\Services\Orders;

use App\Enums\MediaStage;
use App\Enums\OrderStatus;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Сбор данных о нужной детали при диагностике "Нужна деталь" (ТЗ п.32.1) —
 * запускается из DiagnosisFlow вместо немедленного перехода в WAITING_PART,
 * сам переход происходит по завершении сбора данных (включая необязательное
 * фото/видео детали, ТЗ п.32.1 "можно приложить").
 */
class PartRequestFlow
{
    private const KIND = 'waiting_part';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
        private readonly MasterOrderGuard $guard,
        private readonly MediaCollector $mediaCollector,
    ) {}

    public function start(User $master, int $chatId, int $orderNumber): void
    {
        if (! $this->guard->resolve($master, $orderNumber, OrderStatus::DIAGNOSTICS, $chatId)) {
            return;
        }

        PendingInput::query()->updateOrCreate(
            ['user_id' => $master->id],
            [
                'kind' => self::KIND,
                'payload' => ['order_number' => $orderNumber, 'step' => 'name'],
                'expires_at' => now()->addMinutes(20),
            ]
        );

        $this->telegram->sendMessage($chatId, 'Название детали:');
    }

    public function handle(User $master, PendingInput $pending, int $chatId, ?string $text, ?string $callback): void
    {
        if ($callback === 'media_done') {
            $this->finish($master, $chatId, $pending);

            return;
        }

        $skip = $callback === 'skip';
        $text = $text !== null ? trim($text) : null;

        match ($pending->get('step')) {
            'name' => $this->handleName($pending, $chatId, $text),
            'article' => $this->handleArticle($pending, $chatId, $text, $skip),
            'price' => $this->handlePrice($pending, $chatId, $text, $skip),
            'comment' => $this->handleComment($pending, $chatId, $text, $skip),
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $photo
     * @param  array<string, mixed>|null  $video
     */
    public function handleMedia(User $master, PendingInput $pending, int $chatId, ?array $photo, ?array $video): void
    {
        if ($pending->get('step') !== 'media') {
            $this->telegram->sendMessage($chatId, 'Сначала заполни данные о детали.');

            return;
        }

        $orderNumber = (int) $pending->get('order_number');
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::DIAGNOSTICS, $chatId);

        if (! $order) {
            return;
        }

        $this->mediaCollector->handleIncoming($order, $master, MediaStage::PART, $chatId, $photo, $video);
    }

    private function handleName(PendingInput $pending, int $chatId, ?string $text): void
    {
        if (! $text) {
            $this->telegram->sendMessage($chatId, 'Название детали:');

            return;
        }

        $pending->put('part_name', $text);
        $pending->put('step', 'article');
        $pending->save();

        $this->promptOptional($chatId, 'Артикул детали (или пропусти):');
    }

    private function handleArticle(PendingInput $pending, int $chatId, ?string $text, bool $skip): void
    {
        if (! $skip && ! $text) {
            $this->promptOptional($chatId, 'Артикул детали (или пропусти):');

            return;
        }

        $pending->put('part_article', $skip ? null : $text);
        $pending->put('step', 'price');
        $pending->save();

        $this->promptOptional($chatId, 'Ориентировочная закупочная цена, ₽ (или пропусти):');
    }

    private function handlePrice(PendingInput $pending, int $chatId, ?string $text, bool $skip): void
    {
        if ($skip) {
            $pending->put('part_purchase_price', null);
            $pending->put('step', 'comment');
            $pending->save();
            $this->promptOptional($chatId, 'Комментарий (или пропусти):');

            return;
        }

        if (! $text || ! ctype_digit($text)) {
            $this->telegram->sendMessage($chatId, 'Введи число рублей или пропусти:', $this->skipKeyboard());

            return;
        }

        $pending->put('part_purchase_price', (int) $text);
        $pending->put('step', 'comment');
        $pending->save();

        $this->promptOptional($chatId, 'Комментарий (или пропусти):');
    }

    private function handleComment(PendingInput $pending, int $chatId, ?string $text, bool $skip): void
    {
        if (! $skip && ! $text) {
            $this->promptOptional($chatId, 'Комментарий (или пропусти):');

            return;
        }

        $pending->put('part_comment', $skip ? null : $text);
        $pending->put('step', 'media');
        $pending->save();

        $this->telegram->sendMessage(
            $chatId,
            'Добавь фото и/или видео детали (необязательно). Когда закончишь — нажми «Медиа закончены».',
            ['inline_keyboard' => [[['text' => 'Медиа закончены', 'callback_data' => 'part:media_done']]]]
        );
    }

    private function finish(User $master, int $chatId, PendingInput $pending): void
    {
        $orderNumber = (int) $pending->get('order_number');
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::DIAGNOSTICS, $chatId);

        $partName = $pending->get('part_name');
        $partArticle = $pending->get('part_article');
        $partPrice = $pending->get('part_purchase_price');
        $comment = $pending->get('part_comment');

        $pending->delete();

        if (! $order) {
            return;
        }

        try {
            $order = $this->statusMachine->transition(
                $order,
                OrderStatus::WAITING_PART,
                $master,
                comment: 'Нужна деталь: '.$partName,
                attributes: [
                    'part_name' => $partName,
                    'part_article' => $partArticle,
                    'part_purchase_price' => $partPrice,
                    'part_comment' => $comment,
                ],
            );
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $order->load('master');
        $this->telegram->sendMessage($chatId, "Заявка {$order->code()} переведена в ожидание детали.");
        $this->notifier->partRequested($order);
    }

    private function promptOptional(int $chatId, string $text): void
    {
        $this->telegram->sendMessage($chatId, $text, $this->skipKeyboard());
    }

    /**
     * @return array<string, mixed>
     */
    private function skipKeyboard(): array
    {
        return ['inline_keyboard' => [[['text' => 'Пропустить', 'callback_data' => 'part:skip']]]];
    }
}

<?php

namespace App\Services\Telegram;

use App\Models\OrderDraft;
use App\Models\PendingInput;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Orders\ContactAttemptFlow;
use App\Services\Orders\DiagnosisFlow;
use App\Services\Orders\FieldProgressFlow;
use App\Services\Orders\MasterOrderScreen;
use App\Services\Orders\MasterRosterScreen;
use App\Services\Orders\MediaCollectionFlow;
use App\Services\Orders\MediaViewer;
use App\Services\Orders\OrderDecisionFlow;
use App\Services\Orders\OrderDraftFlow;
use App\Services\Orders\OrderListScreens;
use App\Services\Orders\OrderReassignFlow;
use App\Services\Orders\OrderRescheduleFlow;
use App\Services\Orders\OrderVisitFlow;
use App\Services\Orders\PartRequestFlow;
use App\Services\Orders\PaymentFlow;
use App\Services\Orders\PriceApprovalFlow;
use App\Services\Orders\StatsFlow;
use App\Services\Orders\WarrantyReturnFlow;
use App\Services\Orders\WorkReportFlow;
use App\Services\Users\UserManagementScreen;

/**
 * Общая логика обработки одного Telegram-апдейта — используется и вебхуком
 * (прод/staging), и командой long-polling (локальная разработка, ТЗ п.94).
 *
 * Порядок разбора: сначала — активный многошаговый сценарий пользователя (черновик
 * заявки или ожидание свободного текста/шага/медиа), потом — команды/кнопки
 * верхнего уровня, потом — callback-кнопки по namespace в callback_data.
 */
class UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly BotMenu $menu,
        private readonly OrderDraftFlow $draftFlow,
        private readonly OrderDecisionFlow $decisionFlow,
        private readonly OrderReassignFlow $reassignFlow,
        private readonly OrderListScreens $listScreens,
        private readonly MasterRosterScreen $masterRoster,
        private readonly MasterOrderScreen $masterScreen,
        private readonly FieldProgressFlow $fieldFlow,
        private readonly DiagnosisFlow $diagnosisFlow,
        private readonly PriceApprovalFlow $priceFlow,
        private readonly ContactAttemptFlow $contactFlow,
        private readonly OrderRescheduleFlow $rescheduleFlow,
        private readonly PartRequestFlow $partRequestFlow,
        private readonly OrderVisitFlow $visitFlow,
        private readonly WorkReportFlow $workReportFlow,
        private readonly PaymentFlow $paymentFlow,
        private readonly MediaCollectionFlow $mediaFlow,
        private readonly MediaViewer $mediaViewer,
        private readonly StatsFlow $statsFlow,
        private readonly WarrantyReturnFlow $warrantyFlow,
        private readonly UserManagementScreen $userScreen,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        // ТЗ п.90: Telegram может доставить один update дважды (сеть, ретраи).
        // claim() атомарно "занимает" update_id — второй заход тихо выходит
        // здесь же, до того как затронет любую бизнес-логику.
        if (isset($update['update_id']) && ! TelegramUpdate::claim((int) $update['update_id'])) {
            return;
        }

        $message = $update['message'] ?? null;
        $callbackQuery = $update['callback_query'] ?? null;
        $from = $message['from'] ?? $callbackQuery['from'] ?? null;

        if (! $from) {
            return;
        }

        $chatId = $message['chat']['id'] ?? $callbackQuery['message']['chat']['id'] ?? null;
        $telegramUserId = (int) $from['id'];

        $user = User::query()->where('telegram_user_id', $telegramUserId)->first();

        if (! $user || ! $user->is_active) {
            if ($chatId) {
                $this->telegram->sendMessage(
                    $chatId,
                    "Доступ к системе не предоставлен.\nОбратитесь к администратору."
                );
            }

            if ($callbackQuery) {
                $this->telegram->answerCallbackQuery($callbackQuery['id']);
            }

            return;
        }

        if ($callbackQuery) {
            $this->telegram->answerCallbackQuery($callbackQuery['id']);
            $this->handleCallback($user, $chatId, (string) $callbackQuery['data']);

            return;
        }

        $photo = $message['photo'] ?? null;
        $video = $message['video'] ?? null;

        if ($photo || $video) {
            $this->handleMedia($user, $chatId, $photo, $video);

            return;
        }

        $this->handleMessage($user, $chatId, (string) ($message['text'] ?? ''));
    }

    private function handleMessage(User $user, int $chatId, string $text): void
    {
        if ($text === '/start' || $this->menu->isMenuCommand($user, $text)) {
            $this->exitActiveScenario($user, $chatId);
        } else {
            $draft = $this->activeDraftFor($user);

            if ($draft) {
                $this->draftFlow->handle($user, $draft, $chatId, $text, null);

                return;
            }

            $pending = $this->activePendingInputFor($user);

            if ($pending) {
                match ($pending->kind) {
                    'order_decline_reason' => $this->decisionFlow->declineCustomReasonText($user, $chatId, $pending, $text),
                    'price_approval' => $this->priceFlow->handleEntryText($user, $chatId, $pending, $text),
                    'price_decline_reason' => $this->priceFlow->declineCustomReasonText($user, $chatId, $pending, $text),
                    'reschedule' => $this->rescheduleFlow->handle($user, $pending, $chatId, $text, null),
                    'waiting_part' => $this->partRequestFlow->handle($user, $pending, $chatId, $text, null),
                    'next_visit' => $this->visitFlow->handle($user, $pending, $chatId, $text, null),
                    'work_report' => $this->workReportFlow->handle($user, $pending, $chatId, $text, null),
                    'payment_partial' => $this->paymentFlow->handlePartialAmountText($user, $chatId, $pending, $text),
                    'stats_period' => $this->statsFlow->handleCustomText($user, $chatId, $pending, $text),
                    'warranty_return' => $this->warrantyFlow->handle($user, $pending, $chatId, $text, null),
                    'order_search' => $this->listScreens->handleSearchText($user, $pending, $chatId, $text),
                    default => $pending->delete(),
                };

                return;
            }
        }

        if ($text === '/start') {
            $this->telegram->sendMessage($chatId, $this->menu->welcomeText($user), $this->menu->keyboardFor($user));

            return;
        }

        if ($user->role->isAdminLike()) {
            match ($text) {
                'Новая заявка' => $this->draftFlow->start($user, $chatId),
                'Нераспределённые' => $this->listScreens->unassigned($chatId),
                'Активные' => $this->listScreens->active($chatId),
                'Сегодня' => $this->listScreens->today($chatId),
                'Мастера' => $this->masterRoster->list($chatId),
                'Поиск' => $this->listScreens->promptSearch($user, $chatId),
                'Статистика' => $this->statsFlow->promptPeriod($chatId),
                'Пользователи' => $user->isSuperadmin()
                    ? $this->userScreen->list($chatId)
                    : $this->telegram->sendMessage($chatId, '🚧 Этот экран появится в одной из следующих фаз.'),
                default => $this->telegram->sendMessage($chatId, '🚧 Этот экран появится в одной из следующих фаз.'),
            };

            return;
        }

        if ($text === 'Мои активные') {
            $this->masterScreen->myActive($user, $chatId);

            return;
        }

        if ($text === 'Сегодня') {
            $this->masterScreen->today($user, $chatId);

            return;
        }

        if ($text === 'Завтра') {
            $this->masterScreen->tomorrow($user, $chatId);

            return;
        }

        if ($text === 'История') {
            $this->masterScreen->history($user, $chatId);

            return;
        }

        if ($text === 'Моя статистика') {
            $this->statsFlow->promptPeriod($chatId);

            return;
        }

        $this->telegram->sendMessage($chatId, '🚧 Этот экран появится в одной из следующих фаз.');
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $photo
     * @param  array<string, mixed>|null  $video
     */
    private function handleMedia(User $user, int $chatId, ?array $photo, ?array $video): void
    {
        $pending = $this->activePendingInputFor($user);

        if (! $pending) {
            $this->telegram->sendMessage($chatId, '🚧 Сейчас медиа не ожидается.');

            return;
        }

        match ($pending->kind) {
            'media_collection' => $this->mediaFlow->handleMedia($user, $pending, $chatId, $photo, $video),
            'waiting_part' => $this->partRequestFlow->handleMedia($user, $pending, $chatId, $photo, $video),
            'work_report' => $this->workReportFlow->handleMedia($user, $pending, $chatId, $photo, $video),
            default => $this->telegram->sendMessage($chatId, '🚧 Сейчас медиа не ожидается.'),
        };
    }

    private function handleCallback(User $user, int $chatId, string $data): void
    {
        $parts = explode(':', $data);
        $namespace = $parts[0] ?? '';

        if ($namespace === 'draft') {
            $draft = $this->activeDraftFor($user);

            if (! $draft) {
                $this->telegram->sendMessage($chatId, 'Сценарий создания заявки истёк. Начни заново — «Новая заявка».');

                return;
            }

            $this->draftFlow->handle($user, $draft, $chatId, null, substr($data, strlen('draft:')));

            return;
        }

        if ($namespace === 'resched') {
            $pending = $this->requirePending($user, 'reschedule', $chatId, 'Сценарий переноса истёк. Начни заново — кнопка «Перенести».');

            if ($pending) {
                $this->rescheduleFlow->handle($user, $pending, $chatId, null, substr($data, strlen('resched:')));
            }

            return;
        }

        if ($namespace === 'part') {
            $pending = $this->requirePending($user, 'waiting_part', $chatId, 'Сценарий оформления детали истёк. Начни заново с диагностики.');

            if ($pending) {
                $this->partRequestFlow->handle($user, $pending, $chatId, null, substr($data, strlen('part:')));
            }

            return;
        }

        if ($namespace === 'visit') {
            $pending = $this->requirePending($user, 'next_visit', $chatId, 'Сценарий планирования визита истёк. Начни заново.');

            if ($pending) {
                $this->visitFlow->handle($user, $pending, $chatId, null, substr($data, strlen('visit:')));
            }

            return;
        }

        if ($namespace === 'work_report') {
            $pending = $this->requirePending($user, 'work_report', $chatId, 'Сценарий завершения ремонта истёк. Начни заново.');

            if ($pending) {
                $this->workReportFlow->handle($user, $pending, $chatId, null, substr($data, strlen('work_report:')));
            }

            return;
        }

        if ($namespace === 'warranty') {
            $pending = $this->requirePending($user, 'warranty_return', $chatId, 'Сценарий гарантийного обращения истёк. Начни заново — кнопка «Гарантия».');

            if ($pending) {
                $this->warrantyFlow->handle($user, $pending, $chatId, null, substr($data, strlen('warranty:')));
            }

            return;
        }

        if ($namespace === 'media') {
            $pending = $this->requirePending($user, 'media_collection', $chatId, 'Сценарий добавления медиа истёк. Начни заново.');

            if ($pending && substr($data, strlen('media:')) === 'done') {
                $this->mediaFlow->finish($pending, $chatId);
            }

            return;
        }

        if ($namespace === 'stats') {
            if (isset($parts[1])) {
                $this->statsFlow->handlePeriod($user, $chatId, $parts[1]);
            }

            return;
        }

        if ($namespace === 'order') {
            $this->handleOrderCallback($user, $chatId, $parts);

            return;
        }

        if ($namespace === 'users') {
            if (! $user->isSuperadmin()) {
                return;
            }

            if (($parts[1] ?? null) === 'toggle' && isset($parts[2])) {
                $this->userScreen->toggle($user, $chatId, $parts[2]);
            }

            return;
        }

        if ($namespace === 'reassign' && ($parts[1] ?? null) === 'master' && isset($parts[2], $parts[3])) {
            if (! $user->role->isAdminLike()) {
                return;
            }

            $this->reassignFlow->assign($user, $chatId, (int) $parts[2], $parts[3]);
        }
    }

    /**
     * @param  list<string>  $parts
     */
    private function handleOrderCallback(User $user, int $chatId, array $parts): void
    {
        $action = $parts[1] ?? null;
        $orderNumber = isset($parts[2]) ? (int) $parts[2] : null;

        if (! $orderNumber) {
            return;
        }

        match ($action) {
            'accept' => $this->decisionFlow->accept($user, $chatId, $orderNumber),
            'decline' => $this->decisionFlow->declineStart($user, $chatId, $orderNumber),
            'decline_reason' => isset($parts[3]) ? $this->decisionFlow->declineReason($user, $chatId, $orderNumber, $parts[3]) : null,
            'reassign' => $user->role->isAdminLike() ? $this->reassignFlow->promptMaster($user, $chatId, $orderNumber) : null,
            'depart' => $this->fieldFlow->depart($user, $chatId, $orderNumber),
            'arrive' => $this->fieldFlow->arrive($user, $chatId, $orderNumber),
            'diagnose' => $this->diagnosisFlow->start($user, $chatId, $orderNumber),
            'diagnosis' => isset($parts[3]) ? $this->diagnosisFlow->resolve($user, $chatId, $orderNumber, $parts[3]) : null,
            'reschedule' => $this->rescheduleFlow->start($user, $chatId, $orderNumber),
            'no_contact' => $this->contactFlow->promptReason($user, $chatId, $orderNumber),
            'contact_result' => isset($parts[3]) ? $this->contactFlow->record($user, $chatId, $orderNumber, $parts[3]) : null,
            'no_contact_escalate' => $user->role->isAdminLike() ? $this->contactFlow->escalate($user, $chatId, $orderNumber) : null,
            'price_start' => $this->priceFlow->start($user, $chatId, $orderNumber),
            'price_approve' => $this->priceFlow->approve($user, $chatId, $orderNumber),
            'price_decline' => $this->priceFlow->declineStart($user, $chatId, $orderNumber),
            'price_decline_reason' => isset($parts[3]) ? $this->priceFlow->declineReason($user, $chatId, $orderNumber, $parts[3]) : null,
            'visit_next' => $this->visitFlow->start($user, $chatId, $orderNumber),
            'complete' => $this->workReportFlow->start($user, $chatId, $orderNumber),
            'pay_full' => $user->role->isAdminLike() ? $this->paymentFlow->markFullyPaid($user, $chatId, $orderNumber) : null,
            'pay_partial' => $user->role->isAdminLike() ? $this->paymentFlow->promptPartialAmount($user, $chatId, $orderNumber) : null,
            'pay_none' => $user->role->isAdminLike() ? $this->paymentFlow->markUnpaid($user, $chatId, $orderNumber) : null,
            'add_media' => $this->mediaFlow->start($user, $chatId, $orderNumber),
            'view_media' => $this->mediaViewer->show($user, $chatId, $orderNumber),
            'warranty' => $user->role->isAdminLike() ? $this->warrantyFlow->start($user, $chatId, $orderNumber) : null,
            default => null,
        };
    }

    /**
     * Запасной выход: команда меню или /start прервали активный черновик заявки
     * или PendingInput-сценарий. Раньше проверка черновика/pending шла раньше
     * этих команд без исключений — пользователь, ошибившийся вводом посреди
     * сценария, не мог выйти из него никак, кроме как подобрать корректный
     * формат (см. живой QA-прогон 16.09.2026, vault/Фазы/Фаза 07).
     */
    private function exitActiveScenario(User $user, int $chatId): void
    {
        $draft = $this->activeDraftFor($user);

        if ($draft) {
            $draft->delete();
            $this->telegram->sendMessage($chatId, '❌ Черновик заявки отменён.');

            return;
        }

        $pending = $this->activePendingInputFor($user);

        if ($pending) {
            $pending->delete();
            $this->telegram->sendMessage($chatId, '❌ Текущее действие отменено.');
        }
    }

    private function activeDraftFor(User $user): ?OrderDraft
    {
        $draft = OrderDraft::query()->where('created_by_user_id', $user->id)->first();

        if ($draft && $draft->isExpired()) {
            $draft->delete();

            return null;
        }

        return $draft;
    }

    private function activePendingInputFor(User $user): ?PendingInput
    {
        $pending = PendingInput::query()->where('user_id', $user->id)->first();

        if ($pending && $pending->isExpired()) {
            $pending->delete();

            return null;
        }

        return $pending;
    }

    private function requirePending(User $user, string $kind, int $chatId, string $expiredMessage): ?PendingInput
    {
        $pending = $this->activePendingInputFor($user);

        if (! $pending || $pending->kind !== $kind) {
            $this->telegram->sendMessage($chatId, $expiredMessage);

            return null;
        }

        return $pending;
    }
}

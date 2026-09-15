<?php

namespace App\Enums;

/**
 * Шаги сценария создания заявки (ТЗ п.18.1–18.12). ADDRESS_ZONE — наше добавление
 * к исходным 12 шагам: без него подбор мастера по географии (п.18.11) нечем фильтровать,
 * так как геокодирования адреса в MVP нет (ТЗ п.18.8 — SHOULD, не входит в фазу 01).
 */
enum OrderDraftStep: string
{
    case APPLIANCE_TYPE = 'APPLIANCE_TYPE';
    case BRAND = 'BRAND';
    case MODEL = 'MODEL';
    case SYMPTOM = 'SYMPTOM';
    case SYMPTOM_CUSTOM = 'SYMPTOM_CUSTOM';
    case DESCRIPTION = 'DESCRIPTION';
    case CUSTOMER_NAME = 'CUSTOMER_NAME';
    case CUSTOMER_PHONE = 'CUSTOMER_PHONE';
    case ADDRESS = 'ADDRESS';
    case ADDRESS_ZONE = 'ADDRESS_ZONE';
    case DATE = 'DATE';
    case DATE_CUSTOM = 'DATE_CUSTOM';
    case TIME_SLOT = 'TIME_SLOT';
    case TIME_SLOT_CUSTOM = 'TIME_SLOT_CUSTOM';
    case MASTER = 'MASTER';
    case CONFIRM = 'CONFIRM';
}

<?php

namespace App\Enums;

/**
 * ТЗ п.50. Архитектура должна допускать расширение до DOCUMENT/AUDIO/VOICE
 * (ТЗ п.48) — добавится новым кейсом сюда, когда понадобится.
 */
enum MediaType: string
{
    case PHOTO = 'PHOTO';
    case VIDEO = 'VIDEO';
}

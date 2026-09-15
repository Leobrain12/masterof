<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeSlot extends Model
{
    protected $fillable = ['label', 'starts_at', 'ends_at', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

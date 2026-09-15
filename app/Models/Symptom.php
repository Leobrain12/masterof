<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Symptom extends Model
{
    protected $fillable = ['appliance_type_id', 'text', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function applianceType(): BelongsTo
    {
        return $this->belongsTo(ApplianceType::class);
    }
}

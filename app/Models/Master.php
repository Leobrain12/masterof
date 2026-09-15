<?php

namespace App\Models;

use App\Enums\MasterStatus;
use Database\Factories\MasterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Master extends Model
{
    /** @use HasFactory<MasterFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'name', 'phone', 'status',
        'commission_type', 'commission_value', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'status' => MasterStatus::class,
            'commission_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applianceTypes(): BelongsToMany
    {
        return $this->belongsToMany(ApplianceType::class, 'master_appliance_type');
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'master_brand');
    }

    public function geoZones(): BelongsToMany
    {
        return $this->belongsToMany(GeoZone::class, 'master_geo_zone');
    }

    /**
     * Мастер активен и в статусе, допускающем назначение (ТЗ п.8.1).
     */
    public function isAssignable(): bool
    {
        return $this->is_active && $this->status->isAssignable();
    }
}

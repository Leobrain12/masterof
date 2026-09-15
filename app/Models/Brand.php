<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Brand extends Model
{
    protected $fillable = ['name', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function masters(): BelongsToMany
    {
        return $this->belongsToMany(Master::class, 'master_brand');
    }
}

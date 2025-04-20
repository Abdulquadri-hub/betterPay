<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Beneficiary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'service_type',
        'provider_id',
        'identifier',
        'details',
        'is_favorite',
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
        'details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('service_type', $type);
    }

    public function scopeFavorites($query)
    {
        return $query->where('is_favorite', true);
    }
}

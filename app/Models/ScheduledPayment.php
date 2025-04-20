<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'service_type',
        'provider_id',
        'recipient',
        'amount',
        'frequency',
        'next_payment_date',
        'last_processed_at',
        'title',
        'details',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'next_payment_date' => 'datetime',
        'last_processed_at' => 'datetime',
        'is_active' => 'boolean',
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('service_type', $type);
    }

    public function scopeDue($query)
    {
        return $query->where('next_payment_date', '<=', now())
                     ->where('is_active', true);
    }
}

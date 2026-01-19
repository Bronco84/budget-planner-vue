<?php

namespace App\Models;

use App\Services\BudgetService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transfer extends Model
{
    use HasFactory;

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Clear projection caches when transfers are created/updated/deleted
        static::created(function (Transfer $transfer) {
            BudgetService::clearAccountCaches($transfer->from_account_id);
            BudgetService::clearAccountCaches($transfer->to_account_id);
        });

        static::updated(function (Transfer $transfer) {
            BudgetService::clearAccountCaches($transfer->from_account_id);
            BudgetService::clearAccountCaches($transfer->to_account_id);
            
            // Also clear for old accounts if they changed
            if ($transfer->isDirty('from_account_id') && $transfer->getOriginal('from_account_id')) {
                BudgetService::clearAccountCaches($transfer->getOriginal('from_account_id'));
            }
            if ($transfer->isDirty('to_account_id') && $transfer->getOriginal('to_account_id')) {
                BudgetService::clearAccountCaches($transfer->getOriginal('to_account_id'));
            }
        });

        static::deleted(function (Transfer $transfer) {
            BudgetService::clearAccountCaches($transfer->from_account_id);
            BudgetService::clearAccountCaches($transfer->to_account_id);
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'budget_id',
        'from_account_id',
        'to_account_id',
        'amount_in_cents',
        'date',
        'description',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount_in_cents' => 'integer',
        'date' => 'date:Y-m-d',
    ];

    /**
     * Get the budget that owns the transfer.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the source account (money leaves this account).
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    /**
     * Get the destination account (money enters this account).
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    /**
     * Get the transactions generated from this transfer.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Check if this transfer is in the future (projected).
     */
    public function isFuture(): bool
    {
        return $this->date > now()->startOfDay();
    }

    /**
     * Format amount for display.
     */
    public function getFormattedAmountAttribute(): string
    {
        return '$' . number_format($this->amount_in_cents / 100, 2);
    }

    /**
     * Get the default description for this transfer.
     */
    public function getDefaultDescriptionAttribute(): string
    {
        if ($this->description) {
            return $this->description;
        }

        $from = $this->fromAccount?->name ?? 'Unknown';
        $to = $this->toAccount?->name ?? 'Unknown';

        return "Transfer: {$from} → {$to}";
    }
}

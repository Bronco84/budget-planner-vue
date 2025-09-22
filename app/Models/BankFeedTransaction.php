<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BankFeedTransaction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'bank_feed_id',
        'source_transaction_id',
        'raw_data',
        'amount',
        'date',
        'datetime',
        'description',
        'category',
        'merchant_name',
        'status',
        'pending',
        'pending_transaction_id',
        'currency_code',
        'metadata',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'raw_data' => 'array',
        'metadata' => 'array',
        'amount' => 'decimal:2',
        'date' => 'date',
        'datetime' => 'datetime',
        'pending' => 'boolean',
        'processed_at' => 'datetime',
    ];

    /**
     * Status constants.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_CLEARED = 'cleared';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Get all available statuses.
     *
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_CLEARED => 'Cleared',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * Get the bank feed that this transaction belongs to.
     */
    public function bankFeed(): BelongsTo
    {
        return $this->belongsTo(BankFeed::class);
    }

    /**
     * Get the transaction created from this bank feed transaction.
     */
    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'bank_feed_transaction_id');
    }

    /**
     * Check if this bank feed transaction has been processed into a Transaction.
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Check if this transaction is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING || $this->pending;
    }

    /**
     * Check if this transaction is cleared.
     */
    public function isCleared(): bool
    {
        return $this->status === self::STATUS_CLEARED && !$this->pending;
    }

    /**
     * Check if this transaction is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Get the formatted amount for display.
     */
    public function getFormattedAmountAttribute(): string
    {
        return '$' . number_format($this->amount, 2);
    }

    /**
     * Get the amount in cents (useful for consistency with Transaction model).
     */
    public function getAmountInCentsAttribute(): int
    {
        return (int) round($this->amount * 100);
    }

    /**
     * Get the source type from the related bank feed.
     */
    public function getSourceTypeAttribute(): ?string
    {
        return $this->bankFeed?->source_type;
    }

    /**
     * Get a safe version of raw data without sensitive information.
     */
    public function getSafeRawData(): array
    {
        $rawData = $this->raw_data ?? [];
        
        // Remove potentially sensitive keys
        $sensitiveKeys = ['account_id', 'access_token', 'secret'];
        foreach ($sensitiveKeys as $key) {
            unset($rawData[$key]);
        }
        
        return $rawData;
    }

    /**
     * Create a Transaction from this bank feed transaction.
     */
    public function createTransaction(): Transaction
    {
        if ($this->isProcessed()) {
            throw new \Exception('This bank feed transaction has already been processed.');
        }

        $transaction = Transaction::create([
            'budget_id' => $this->bankFeed->budget_id,
            'account_id' => $this->bankFeed->account_id,
            'bank_feed_transaction_id' => $this->id,
            'description' => $this->description,
            'category' => $this->category ?? 'Uncategorized',
            'amount_in_cents' => $this->getAmountInCentsAttribute(),
            'date' => $this->date,
            'import_source' => $this->bankFeed->source_type,
            'notes' => null,
        ]);

        // Mark as processed
        $this->update(['processed_at' => now()]);

        return $transaction;
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get unprocessed transactions.
     */
    public function scopeUnprocessed($query)
    {
        return $query->whereNull('processed_at');
    }

    /**
     * Scope to get processed transactions.
     */
    public function scopeProcessed($query)
    {
        return $query->whereNotNull('processed_at');
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope to get pending transactions.
     */
    public function scopePending($query)
    {
        return $query->where(function ($q) {
            $q->where('status', self::STATUS_PENDING)
              ->orWhere('pending', true);
        });
    }

    /**
     * Scope to get cleared transactions.
     */
    public function scopeCleared($query)
    {
        return $query->where('status', self::STATUS_CLEARED)
                     ->where('pending', false);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankFeed extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'budget_id',
        'source_type',
        'connection_config',
        'source_account_id',
        'institution_name',
        'last_sync_at',
        'status',
        'error_message',
        'current_balance_cents',
        'available_balance_cents',
        'balance_updated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'connection_config' => 'array',
        'current_balance_cents' => 'integer',
        'available_balance_cents' => 'integer',
        'last_sync_at' => 'datetime',
        'balance_updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'connection_config', // Hide sensitive connection data
    ];

    /**
     * Source type constants.
     */
    const SOURCE_PLAID = 'plaid';
    const SOURCE_AIRTABLE = 'airtable';
    const SOURCE_CSV = 'csv';
    const SOURCE_OFX = 'ofx';
    const SOURCE_MANUAL = 'manual';

    /**
     * Status constants.
     */
    const STATUS_ACTIVE = 'active';
    const STATUS_ERROR = 'error';
    const STATUS_DISCONNECTED = 'disconnected';
    const STATUS_PENDING = 'pending';

    /**
     * Get all available source types.
     *
     * @return array<string, string>
     */
    public static function getSourceTypes(): array
    {
        return [
            self::SOURCE_PLAID => 'Plaid',
            self::SOURCE_AIRTABLE => 'Airtable',
            self::SOURCE_CSV => 'CSV Import',
            self::SOURCE_OFX => 'OFX Import',
            self::SOURCE_MANUAL => 'Manual Entry',
        ];
    }

    /**
     * Get all available statuses.
     *
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending Connection',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_ERROR => 'Error',
            self::STATUS_DISCONNECTED => 'Disconnected',
        ];
    }

    /**
     * Get the account that this bank feed belongs to.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the budget that this bank feed belongs to.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get all bank feed transactions for this feed.
     */
    public function bankFeedTransactions(): HasMany
    {
        return $this->hasMany(BankFeedTransaction::class);
    }

    /**
     * Get transactions created from this bank feed.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'bank_feed_transaction_id', 'id')
            ->whereHas('bankFeedTransaction', function ($query) {
                $query->where('bank_feed_id', $this->id);
            });
    }

    /**
     * Check if the bank feed is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if the bank feed has an error.
     */
    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * Check if the bank feed is disconnected.
     */
    public function isDisconnected(): bool
    {
        return $this->status === self::STATUS_DISCONNECTED;
    }

    /**
     * Get a safe version of connection config without sensitive data.
     */
    public function getSafeConnectionConfig(): array
    {
        $config = $this->connection_config ?? [];
        
        // Remove sensitive keys
        $sensitiveKeys = ['access_token', 'secret', 'api_key', 'password'];
        foreach ($sensitiveKeys as $key) {
            if (isset($config[$key])) {
                $config[$key] = '[HIDDEN]';
            }
        }
        
        return $config;
    }

    /**
     * Format the current balance for display.
     */
    public function getFormattedCurrentBalanceAttribute(): string
    {
        if ($this->current_balance_cents === null) {
            return 'N/A';
        }
        
        return '$' . number_format($this->current_balance_cents / 100, 2);
    }

    /**
     * Format the available balance for display.
     */
    public function getFormattedAvailableBalanceAttribute(): string
    {
        if ($this->available_balance_cents === null) {
            return 'N/A';
        }
        
        return '$' . number_format($this->available_balance_cents / 100, 2);
    }

    /**
     * Scope to filter by source type.
     */
    public function scopeBySource($query, string $sourceType)
    {
        return $query->where('source_type', $sourceType);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get active bank feeds.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
<?php

namespace App\Models;

use App\Services\BudgetService;
use App\Services\RecurringTransactionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Transaction extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Clear projection caches when transactions are created/updated/deleted
        static::created(function (Transaction $transaction) {
            if ($transaction->account_id) {
                BudgetService::clearAccountCaches($transaction->account_id);
            }
            // If linked to a template, clear its dynamic amount cache
            if ($transaction->recurring_transaction_template_id) {
                RecurringTransactionService::clearDynamicAmountCache($transaction->recurring_transaction_template_id);
            }
        });

        static::updated(function (Transaction $transaction) {
            if ($transaction->account_id) {
                BudgetService::clearAccountCaches($transaction->account_id);
            }
            // Also clear for old account if account changed
            if ($transaction->isDirty('account_id') && $transaction->getOriginal('account_id')) {
                BudgetService::clearAccountCaches($transaction->getOriginal('account_id'));
            }
            // If linked to a template, clear its dynamic amount cache
            if ($transaction->recurring_transaction_template_id) {
                RecurringTransactionService::clearDynamicAmountCache($transaction->recurring_transaction_template_id);
            }
        });

        static::deleted(function (Transaction $transaction) {
            if ($transaction->account_id) {
                BudgetService::clearAccountCaches($transaction->account_id);
            }
            if ($transaction->recurring_transaction_template_id) {
                RecurringTransactionService::clearDynamicAmountCache($transaction->recurring_transaction_template_id);
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'budget_id',
        'account_id',
        'description',
        'category',
        'amount_in_cents',
        'date',
        'plaid_transaction_id',
        'is_plaid_imported',
        'is_reconciled',
        'recurring_transaction_template_id',
        'transfer_id',
        'bank_feed_transaction_id',
        'import_source',
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
        'is_plaid_imported' => 'boolean',
        'is_reconciled' => 'boolean',
    ];

    /**
     * Get the budget that owns the transaction.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the account that the transaction belongs to.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the Plaid transaction data, if this was imported from Plaid.
     */
    public function plaidTransaction(): BelongsTo
    {
        return $this->belongsTo(PlaidTransaction::class, 'plaid_transaction_id', 'plaid_transaction_id');
    }

    /**
     * Get the recurring transaction template that generated this transaction.
     */
    public function recurringTemplate(): BelongsTo
    {
        return $this->belongsTo(RecurringTransactionTemplate::class, 'recurring_transaction_template_id');
    }

    /**
     * Get the recurring transaction template that this transaction is linked to.
     */
    public function recurringTransactionTemplate()
    {
        return $this->belongsTo(RecurringTransactionTemplate::class);
    }

    /**
     * Get the bank feed transaction that generated this transaction.
     */
    public function bankFeedTransaction(): BelongsTo
    {
        return $this->belongsTo(BankFeedTransaction::class);
    }

    /**
     * Get the transfer that generated this transaction (if any).
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    /**
     * Check if this transaction is part of a transfer.
     */
    public function isTransfer(): bool
    {
        return $this->transfer_id !== null;
    }

    /**
     * Check if this transaction was imported from a bank feed.
     */
    public function isImported(): bool
    {
        return $this->import_source !== 'manual';
    }

    /**
     * Check if this transaction was imported from Plaid.
     */
    public function isPlaidImported(): bool
    {
        return $this->import_source === 'plaid' || $this->is_plaid_imported;
    }

    /**
     * Check if this transaction was imported from Airtable.
     */
    public function isAirtableImported(): bool
    {
        return $this->import_source === 'airtable';
    }

    /**
     * Get the import source display name.
     */
    public function getImportSourceDisplayAttribute(): string
    {
        return match($this->import_source) {
            'plaid' => 'Plaid',
            'airtable' => 'Airtable',
            'csv' => 'CSV Import',
            'ofx' => 'OFX Import',
            'manual' => 'Manual Entry',
            default => 'Unknown',
        };
    }

    /**
     * Get all file attachments for this transaction.
     */
    public function fileAttachments(): MorphMany
    {
        return $this->morphMany(FileAttachment::class, 'attachable');
    }

    /**
     * Format amount for display.
     */
    public function getFormattedAmountAttribute(): string
    {
        return '$' . number_format($this->amount_in_cents / 100, 2);
    }

    public function scopeFromFuture(\Illuminate\Database\Eloquent\Builder $query, ?string $date = null): \Illuminate\Database\Eloquent\Builder
    {
        return $query->when(
            isset($date),
            fn($query) => $query->where('date', '>=', $date),
            fn($query) => $query->where('date', '>=', now()->toDate()->format('Y-m-d'))
        );
    }

    /**
     * Get activity log options.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'description',
                'amount_in_cents',
                'date',
                'category',
                'account_id',
                'notes',
                'is_reconciled'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('transaction')
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Transaction created',
                'updated' => 'Transaction updated',
                'deleted' => 'Transaction deleted',
                default => "Transaction {$eventName}",
            });
    }

    /**
     * Get formatted activity log for this transaction.
     */
    public function getActivityLogFormatted()
    {
        return $this->activities()
            ->with('causer')
            ->latest()
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'event' => $activity->event,
                    'properties' => $activity->properties->toArray(), // Convert Collection to array
                    'causer' => $activity->causer ? [
                        'id' => $activity->causer->id,
                        'name' => $activity->causer->name,
                        'email' => $activity->causer->email,
                    ] : null,
                    'created_at' => $activity->created_at,
                    'updated_at' => $activity->updated_at,
                ];
            });
    }

    /**
     * Log a custom activity for this transaction.
     */
    public function logActivity(string $description, array $properties = [], ?int $causerId = null): void
    {
        $activityBuilder = activity('transaction_custom')
            ->performedOn($this)
            ->withProperties($properties);

        if ($causerId) {
            $user = User::find($causerId);
            if ($user) {
                $activityBuilder->causedBy($user);
            }
        }

        $activityBuilder->log($description);
    }
}

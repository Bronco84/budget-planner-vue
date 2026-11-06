<?php

namespace App\Models;

use App\Models\Concerns\SyncsCategoryAssignment;
use App\Services\BudgetService;
use App\Services\FileService;
use App\Services\RecurringTransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Transaction extends Model
{
    use HasFactory, LogsActivity, SyncsCategoryAssignment;

    /**
     * How a transaction was linked to its recurring template (link_source).
     * Null means unknown (links made before the column existed) and, like
     * every source except 'rule', is never removed by rule re-evaluation.
     */
    const LINK_SOURCE_MANUAL = 'manual';

    const LINK_SOURCE_RULE = 'rule';

    const LINK_SOURCE_ENTITY = 'entity';

    const LINK_SOURCE_DESCRIPTION = 'description';

    const LINK_SOURCE_ANALYSIS = 'analysis';

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // An unlinked transaction has no link source.
        static::saving(function (Transaction $transaction) {
            $attributes = $transaction->getAttributes();

            if (array_key_exists('recurring_transaction_template_id', $attributes)
                && $attributes['recurring_transaction_template_id'] === null
                && ($attributes['link_source'] ?? null) !== null) {
                $transaction->link_source = null;
            }
        });

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

        // Attachments are polymorphic (no FK cascade): remove them, and each file
        // nothing else uses, on every model delete (controller, Nova, console). Paths
        // that merge a row into another move its attachments first. Query deletes
        // (TransferService) call FileService::deleteTransactionAttachments themselves.
        static::deleting(function (Transaction $transaction) {
            app(FileService::class)->deleteTransactionAttachments([$transaction->id]);
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
        'category_id',
        'amount_in_cents',
        'date',
        'plaid_transaction_id',
        'is_plaid_imported',
        'is_reconciled',
        'recurring_transaction_template_id',
        'link_source',
        'transfer_id',
        'bank_feed_transaction_id',
        'import_source',
        'notes',
        'removed_by_bank_at',
        'bank_removal_kept_at',
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
        'removed_by_bank_at' => 'datetime',
        'bank_removal_kept_at' => 'datetime',
    ];

    /**
     * Scope to transactions that count towards balances, totals and projections:
     * everything except pending Plaid rows the bank has dropped (removed_by_bank_at),
     * which stay listed until the user deletes or keeps them.
     */
    public function scopeCountedInTotals(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('removed_by_bank_at'));
    }

    /**
     * Whether the bank dropped this pending transaction (see scopeCountedInTotals).
     */
    public function isRemovedByBank(): bool
    {
        return $this->removed_by_bank_at !== null;
    }

    /**
     * Link this transaction to a recurring template, recording how, and save.
     */
    public function linkToRecurringTemplate(int $templateId, string $source): bool
    {
        $this->recurring_transaction_template_id = $templateId;
        $this->link_source = $source;

        return $this->save();
    }

    /**
     * Get the budget that owns the transaction.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the budget category the transaction is assigned to.
     *
     * Named budgetCategory because the legacy `category` column (kept in sync
     * with the category's name) would shadow a relation called `category`.
     */
    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * The category name to display/group by: the linked category's name, or the
     * legacy free-text category for rows that never matched a category. Grouping
     * by it merges linked rows (even with a stale copied name) with unlinked
     * legacy rows that carry the same category name. Per-category breakdowns
     * use Category::groupingNameResolver instead, which also merges legacy
     * strings by name key (case and surrounding spaces).
     */
    public function getCategoryNameAttribute(): ?string
    {
        if ($this->category_id && $this->budgetCategory) {
            return $this->budgetCategory->name;
        }

        return $this->category;
    }

    /**
     * Scope to transactions assigned to the given category: linked by id, or
     * unlinked legacy rows whose category string matches the category's name
     * trimmed and case-insensitively (Category::nameKey), when the category
     * claims that name (Category::claimsLegacyName).
     */
    public function scopeInCategory(Builder $query, Category $category): Builder
    {
        $claimsLegacy = $category->claimsLegacyName();
        $column = $query->getModel()->qualifyColumn('category');

        return $query->where(function (Builder $q) use ($category, $claimsLegacy, $column) {
            $q->where('category_id', $category->id);

            if ($claimsLegacy) {
                $q->orWhere(function (Builder $legacy) use ($category, $column) {
                    $legacy->whereNull('category_id')
                        ->whereRaw(Category::nameKeySql($column).' = ?', [Category::nameKey($category->name)]);
                });
            }
        });
    }

    /**
     * Whether this transaction belongs to the given category (in-memory
     * counterpart of scopeInCategory, with the same legacy name matching).
     */
    public function isInCategory(Category $category): bool
    {
        if ($this->category_id) {
            return (int) $this->category_id === (int) $category->id;
        }

        $key = Category::nameKey($this->category);

        return $key !== ''
            && $key === Category::nameKey($category->name)
            && $category->claimsLegacyName();
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
     *
     * Raw rows are unique per (account_id, plaid_transaction_id). This relation matches
     * on the Plaid id alone, so when one Item is synced into two budgets it may return
     * the other account's copy; both carry the same Plaid data, so use it for Plaid's
     * fields (name, merchant, counterparties) and query by account for anything else.
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
        return match ($this->import_source) {
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
        return '$'.number_format($this->amount_in_cents / 100, 2);
    }

    public function scopeFromFuture(Builder $query, ?string $date = null): Builder
    {
        return $query->when(
            isset($date),
            fn ($query) => $query->where('date', '>=', $date),
            fn ($query) => $query->where('date', '>=', now()->toDate()->format('Y-m-d'))
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
                'is_reconciled',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('transaction')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
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

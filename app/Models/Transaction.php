<?php

namespace App\Models;

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

    public function scopeFromFuture(\Illuminate\Database\Eloquent\Builder $query, string $date = null): \Illuminate\Database\Eloquent\Builder
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

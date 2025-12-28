<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaidConnection extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'budget_id',
        'plaid_item_id',
        'institution_id',
        'institution_name',
        'institution_logo',
        'institution_url',
        'access_token',
        'status',
        'error_message',
        'last_sync_at',
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'last_sync_at' => 'datetime',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_ERROR = 'error';
    const STATUS_DISCONNECTED = 'disconnected';
    const STATUS_EXPIRED = 'expired';

    /**
     * Get the budget that owns this Plaid connection.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get all Plaid accounts for this connection.
     */
    public function plaidAccounts(): HasMany
    {
        return $this->hasMany(PlaidAccount::class);
    }

    /**
     * Get all linked budget accounts through Plaid accounts.
     */
    public function accounts()
    {
        return $this->hasManyThrough(Account::class, PlaidAccount::class, 'plaid_connection_id', 'id', 'id', 'account_id');
    }

    /**
     * Check if the connection is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Mark the connection as having an error.
     */
    public function markAsError(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Mark the connection as active.
     */
    public function markAsActive(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'error_message' => null,
        ]);
    }

    /**
     * Update the last sync timestamp.
     */
    public function markSynced(): void
    {
        $this->update(['last_sync_at' => now()]);
    }

    /**
     * Scope a query to find connections for a specific institution and budget.
     */
    public function scopeForInstitution($query, Budget $budget, string $institutionName)
    {
        return $query->where('budget_id', $budget->id)
                    ->where('institution_name', $institutionName)
                    ->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Check if the connection has any linked accounts.
     */
    public function hasAccounts(): bool
    {
        return $this->plaidAccounts()->exists();
    }

    /**
     * Get the count of linked accounts.
     */
    public function getAccountCount(): int
    {
        return $this->plaidAccounts()->count();
    }
}

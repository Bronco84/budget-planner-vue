<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Available account types in default order.
     */
    public const ACCOUNT_TYPES = [
        'checking',
        'savings',
        'money market',
        'cd',
        'brokerage',
        'traditional ira',
        'roth ira',
        '401k',
        '403b',
        '457b',
        'stock plan',
        'investment',
        'credit card',
        'credit',
        'loan',
        'line of credit',
        'mortgage',
        'other'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the budgets belonging to the user.
     */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /**
     * Get the budgets linked to the user.
     */
    public function linked_budgets(): BelongsToMany
    {
        return $this->belongsToMany(Budget::class);
    }

    /**
     * Get the user's preferences.
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(UserPreference::class);
    }

    /**
     * Get a user preference value.
     */
    public function getPreference(string $key, $default = null)
    {
        return UserPreference::getUserPreference($this->id, $key, $default);
    }

    /**
     * Set a user preference value.
     */
    public function setPreference(string $key, $value): void
    {
        UserPreference::setUserPreference($this->id, $key, $value);
    }

    /**
     * Get the user's account type order preference.
     */
    public function getAccountTypeOrder(): array
    {
        return $this->getPreference('account_type_order', self::ACCOUNT_TYPES);
    }

    /**
     * Set the user's account type order preference.
     */
    public function setAccountTypeOrder(array $order): void
    {
        $this->setPreference('account_type_order', $order);
    }

    /**
     * Check if the user has access to a specific budget
     */
    public function hasBudget(Budget $budget): bool
    {
        return $this->linked_budgets->contains($budget) || $this->id === $budget->user_id;
    }

    /**
     * Get all budgets accessible to the user (owned + shared).
     */
    public function accessibleBudgets()
    {
        return Budget::where('user_id', $this->id)
            ->orWhereHas('connected_users', function ($query) {
                $query->where('user_id', $this->id);
            })
            ->get();
    }

    /**
     * Get the user's active budget with fallback logic.
     * Returns null if user has no budgets.
     */
    public function getActiveBudget(): ?Budget
    {
        $activeBudgetId = UserPreference::getActiveBudgetId($this->id);

        // If active budget ID is set, try to get it
        if ($activeBudgetId) {
            $budget = Budget::find($activeBudgetId);

            // Verify user still has access to this budget
            if ($budget && $this->hasBudget($budget)) {
                return $budget;
            }

            // If budget doesn't exist or user lost access, clear the preference
            UserPreference::clearActiveBudget($this->id);
        }

        // Fallback: get first accessible budget
        return $this->accessibleBudgets()->first();
    }

    /**
     * Set the user's active budget.
     */
    public function setActiveBudget(?int $budgetId): void
    {
        if ($budgetId) {
            $budget = Budget::find($budgetId);

            // Only set if budget exists and user has access
            if ($budget && $this->hasBudget($budget)) {
                UserPreference::setActiveBudgetId($this->id, $budgetId);
                return;
            }
        }

        // If invalid budget ID or null, clear it
        UserPreference::clearActiveBudget($this->id);
    }
}

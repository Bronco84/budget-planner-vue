<?php

namespace App\Models;

use App\Enums\BudgetRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invitation for an email address to join a budget with a role. Tokens are
 * single use and expire after {@see self::EXPIRY_DAYS} days; only their SHA-256
 * hash is persisted.
 *
 * @property int $id
 * @property int $budget_id
 * @property string $email
 * @property BudgetRole $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class BudgetInvitation extends Model
{
    public const EXPIRY_DAYS = 7;

    protected $fillable = [
        'budget_id',
        'email',
        'role',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'role' => BudgetRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Find a pending (unaccepted, unexpired) invitation by its plaintext token.
     */
    public static function findPendingByToken(?string $token): ?self
    {
        if (! is_string($token) || $token === '' || strlen($token) > 255) {
            return null;
        }

        return static::query()->pending()->where('token_hash', static::hashToken($token))->first();
    }

    /**
     * @param  Builder<BudgetInvitation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function matchesEmail(string $email): bool
    {
        return Str::lower(trim($email)) === Str::lower(trim($this->email));
    }
}

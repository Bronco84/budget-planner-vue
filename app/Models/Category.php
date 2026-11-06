<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class Category extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'budget_id',
        'name',
        'description',
        'amount',
        'color',
        'order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        // Removed percent_used and remaining_amount to prevent N+1 queries
        // These are calculated in controllers when needed
    ];

    /**
     * Get the budget that owns the category.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the transactions linked to this category by id.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the recurring templates linked to this category by id.
     */
    public function recurringTransactionTemplates(): HasMany
    {
        return $this->hasMany(RecurringTransactionTemplate::class);
    }

    /**
     * The key an unlinked legacy `category` string matches a category name by:
     * trimmed of spaces and lowercased, as SyncsCategoryAssignment links new
     * writes. nameKeySql() is its SQL twin; the two must agree on every engine.
     */
    public static function nameKey(?string $name): string
    {
        return mb_strtolower(trim((string) $name, ' '));
    }

    /**
     * SQL for nameKey() over a column: LOWER(TRIM(column)). On MySQL it is
     * compared as bytes, so the column's collation (accent- and
     * pad-insensitive by default) cannot widen the match beyond case and
     * surrounding spaces; SQLite and MySQL then agree with nameKey().
     */
    public static function nameKeySql(string $column): string
    {
        $key = "LOWER(TRIM({$column}))";

        return in_array((new self)->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? "CAST({$key} AS BINARY)"
            : $key;
    }

    /**
     * Whether unlinked legacy rows whose category string matches this
     * category's name key count toward it. When several categories share a
     * key, only the one with the lowest id claims them (the one
     * findForBudgetByName, and so SyncsCategoryAssignment, links new writes
     * to), so a legacy row is never counted twice.
     */
    public function claimsLegacyName(): bool
    {
        $key = static::nameKey($this->name);

        if ($key === '') {
            return false;
        }

        $ownerId = static::query()
            ->withoutGlobalScope('ordered')
            ->where('budget_id', $this->budget_id)
            ->whereRaw(static::nameKeySql('name').' = ?', [$key])
            ->orderBy('id')
            ->value('id');

        return (int) $ownerId === (int) $this->id;
    }

    /**
     * Expense totals of unlinked legacy rows per claiming category id, summed
     * in SQL by name key (rows differing only in case or surrounding spaces
     * merge) and mapped back to the category that claims each key.
     *
     * @param  Builder  $query  transactions already scoped to the budget and filters
     * @param  iterable<int, self>  $categories  the budget's categories
     * @return array<int, int>
     */
    public static function legacySpentByCategory(Builder $query, iterable $categories): array
    {
        $owners = [];

        foreach (collect($categories)->sortBy('id') as $category) {
            $key = static::nameKey($category->name);

            if ($key !== '' && ! isset($owners[$key])) {
                $owners[$key] = (int) $category->id;
            }
        }

        if ($owners === []) {
            return [];
        }

        // Keys are bound as strings: PHP turns numeric-looking array keys into
        // ints, and MySQL would then compare numerically.
        $keys = array_map('strval', array_keys($owners));
        $keySql = static::nameKeySql('transactions.category');

        $rows = $query
            ->whereNull('transactions.category_id')
            ->whereRaw($keySql.' IN ('.implode(', ', array_fill(0, count($keys), '?')).')', $keys)
            ->groupByRaw($keySql)
            ->selectRaw($keySql.' as name_key, SUM(amount_in_cents) as total')
            ->get();

        $spent = [];

        foreach ($rows as $row) {
            $categoryId = $owners[(string) $row->name_key] ?? null;

            if ($categoryId !== null) {
                $spent[$categoryId] = ($spent[$categoryId] ?? 0) + (int) $row->total;
            }
        }

        return $spent;
    }

    /**
     * Find a budget's category by name (trimmed, case-insensitive), e.g. to map
     * a free-text or imported category string onto an existing category.
     */
    public static function findForBudgetByName(int $budgetId, ?string $name): ?self
    {
        $key = mb_strtolower(trim((string) $name));

        if ($key === '') {
            return null;
        }

        return static::query()
            ->where('budget_id', $budgetId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$key])
            ->orderBy('id')
            ->first();
    }

    /**
     * The budget's categories as options for category pickers.
     *
     * @return Collection<int, array{id: int, name: string, color: string|null}>
     */
    public static function optionsForBudget(int $budgetId): Collection
    {
        return static::query()
            ->where('budget_id', $budgetId)
            ->get(['id', 'name', 'color'])
            ->map(fn (self $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'color' => $category->color,
            ]);
    }

    /**
     * Resolve the category columns to store on a transaction or template.
     *
     * A chosen category id (already validated to belong to the budget) wins and
     * its name is copied into the legacy `category` column. Without one, a
     * category string (quick add, an import, or a legacy value kept on edit)
     * is linked to the same-named budget category when one exists and is
     * otherwise stored as-is. Neither means "Uncategorized".
     *
     * @return array{category_id: int|null, category: string|null}
     */
    public static function resolveAssignment(int $budgetId, int|string|null $categoryId, ?string $name): array
    {
        $category = ! empty($categoryId)
            ? static::query()->where('budget_id', $budgetId)->whereKey($categoryId)->first()
            : static::findForBudgetByName($budgetId, $name);

        if ($category) {
            return ['category_id' => $category->id, 'category' => $category->name];
        }

        $legacy = trim((string) $name);

        return ['category_id' => null, 'category' => $legacy !== '' ? $legacy : null];
    }

    /**
     * Scope a query to order categories by their order field.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('ordered', function ($query) {
            $query->orderBy('order');
        });

        // Transactions and templates reference the category by id, but keep a
        // denormalized copy of its name. A rename updates that copy and also
        // adopts unlinked legacy rows that still carry the old name.
        static::updated(function (Category $category) {
            if (! $category->wasChanged('name')) {
                return;
            }

            // Legacy rows match the old name as everywhere else (trimmed,
            // case-insensitive), and only if this category claimed them: a
            // category with a lower id sharing the old name key keeps them
            // (see claimsLegacyName()).
            $oldKey = static::nameKey($category->getOriginal('name'));
            $adoptsLegacy = $oldKey !== '' && ! static::query()
                ->where('budget_id', $category->budget_id)
                ->where('id', '<', $category->id)
                ->whereRaw(static::nameKeySql('name').' = ?', [$oldKey])
                ->exists();

            foreach ([Transaction::class, RecurringTransactionTemplate::class] as $model) {
                $model::query()
                    ->where('budget_id', $category->budget_id)
                    ->where(function ($query) use ($category, $oldKey, $adoptsLegacy) {
                        $query->where('category_id', $category->id);

                        if ($adoptsLegacy) {
                            $query->orWhere(function ($legacy) use ($oldKey) {
                                $legacy->whereNull('category_id')
                                    ->whereRaw(static::nameKeySql('category').' = ?', [$oldKey]);
                            });
                        }
                    })
                    ->update(['category_id' => $category->id, 'category' => $category->name]);
            }
        });
    }

    /**
     * Get the percentage used of the category budget.
     */
    public function getPercentUsedAttribute(): float
    {
        if ($this->amount > 0) {
            // Calculate spent from transactions (negative amounts are expenses)
            $spent = $this->budget->transactions()
                ->countedInTotals()
                ->inCategory($this)
                ->where('amount_in_cents', '<', 0)
                ->sum('amount_in_cents');
            $spentDollars = abs($spent) / 100; // Convert cents to dollars

            return ($spentDollars / $this->amount) * 100;
        }

        return 0;
    }

    /**
     * Get the remaining amount for the category.
     */
    public function getRemainingAmountAttribute(): float
    {
        // Calculate spent from transactions (negative amounts are expenses)
        $spent = $this->budget->transactions()
            ->countedInTotals()
            ->inCategory($this)
            ->where('amount_in_cents', '<', 0)
            ->sum('amount_in_cents');
        $spentDollars = abs($spent) / 100; // Convert cents to dollars

        return $this->amount - $spentDollars;
    }
}

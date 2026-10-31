<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link transactions and recurring templates to their budget's categories by id.
 *
 * The free-text `category` column is kept (it stays in sync with the linked
 * category's name and is the fallback for rows that never matched a category).
 * Existing rows are backfilled by matching the trimmed, case-insensitive
 * category string to a category name within the same budget.
 */
return new class extends Migration
{
    /**
     * Tables that carry a free-text category and a budget_id.
     *
     * @var list<string>
     */
    private array $tables = ['transactions', 'recurring_transaction_templates'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'category_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('category_id')
                    ->nullable()
                    ->after('category')
                    ->index()
                    ->constrained('categories')
                    ->nullOnDelete();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'category_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['category_id']);
                $blueprint->dropIndex(['category_id']);
                $blueprint->dropColumn('category_id');
            });
        }
    }

    /**
     * Match each row's category string to a category of the same budget.
     * When a budget has duplicate names, the first category by order/id wins.
     */
    private function backfill(): void
    {
        $seen = [];

        DB::table('categories')
            ->select(['id', 'budget_id', 'name'])
            ->orderBy('budget_id')
            ->orderBy('order')
            ->orderBy('id')
            ->each(function (object $category) use (&$seen) {
                $key = mb_strtolower(trim((string) $category->name));

                if ($key === '' || isset($seen[$category->budget_id][$key])) {
                    return;
                }

                $seen[$category->budget_id][$key] = true;

                foreach ($this->tables as $table) {
                    DB::table($table)
                        ->where('budget_id', $category->budget_id)
                        ->whereNull('category_id')
                        ->whereNotNull('category')
                        ->whereRaw('LOWER(TRIM(category)) = ?', [$key])
                        ->update(['category_id' => $category->id]);
                }
            });
    }
};

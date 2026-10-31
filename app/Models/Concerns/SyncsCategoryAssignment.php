<?php

namespace App\Models\Concerns;

use App\Models\Category;

/**
 * Keeps a model's `category_id` and its denormalized `category` name in step.
 *
 * - Setting category_id copies the category's name into `category`.
 * - Setting only the `category` string (importers, transfers, legacy writers)
 *   links it to the budget's same-named category (trimmed, case-insensitive)
 *   when one exists, otherwise clears category_id and keeps the string.
 */
trait SyncsCategoryAssignment
{
    public static function bootSyncsCategoryAssignment(): void
    {
        static::saving(function ($model) {
            if (! $model->budget_id) {
                return;
            }

            if ($model->isDirty('category_id')) {
                if ($model->category_id) {
                    $category = Category::query()
                        ->where('budget_id', $model->budget_id)
                        ->whereKey($model->category_id)
                        ->first();

                    if ($category) {
                        $model->category = $category->name;
                    } else {
                        $model->category_id = null;
                    }
                }

                return;
            }

            if ($model->isDirty('category')) {
                $model->category_id = Category::findForBudgetByName($model->budget_id, $model->category)?->id;
            }
        });
    }
}

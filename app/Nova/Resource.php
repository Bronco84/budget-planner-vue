<?php

namespace App\Nova;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource as NovaResource;
use Laravel\Scout\Builder as ScoutBuilder;

abstract class Resource extends NovaResource
{
    /**
     * Build an "index" query for the given resource.
     */
    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query;
    }

    /**
     * Build a Scout search query for the given resource.
     */
    public static function scoutQuery(NovaRequest $request, ScoutBuilder $query): ScoutBuilder
    {
        return $query;
    }

    /**
     * Build a "detail" query for the given resource.
     */
    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return parent::detailQuery($request, $query);
    }

    /**
     * Build a "relatable" query for the given resource.
     *
     * This query determines which instances of the model may be attached to other resources.
     */
    public static function relatableQuery(NovaRequest $request, Builder $query): Builder
    {
        return parent::relatableQuery($request, $query);
    }

    /**
     * A rule for a relation field whose record must belong to the given budget,
     * such as a transaction's account. Nova offers every budget's records in a
     * relation dropdown, while the app only ever pairs records of one budget.
     * A missing record is left to Nova's own Relatable rule.
     *
     * @param  class-string<Model>  $model
     */
    protected static function inBudget(string $model, mixed $budgetId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $budgetId): void {
            if ($value === null || $value === '') {
                return;
            }

            $ownerBudgetId = $model::query()->whereKey($value)->value('budget_id');

            if ($ownerBudgetId !== null && (string) $ownerBudgetId !== (string) $budgetId) {
                $fail('The selected :attribute belongs to a different budget.');
            }
        };
    }
}

<?php

namespace App\Nova;

use Laravel\Nova\Actions\Action;
use Laravel\Nova\Card;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;

class PayoffPlanDebt extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\PayoffPlanDebt>
     */
    public static $model = \App\Models\PayoffPlanDebt::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'id';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Payoff Plan', 'payoffPlan', PayoffPlan::class)
                ->sortable()
                ->required(),

            BelongsTo::make('Account')
                ->sortable()
                ->required(),

            Number::make('Starting Balance', 'starting_balance_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$'.number_format($value / 100, 2) : '$0.00')
                ->help('Balance in cents'),

            Number::make('Interest Rate %', 'interest_rate')
                ->sortable()
                ->step(0.01),

            Number::make('Minimum Payment', 'minimum_payment_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$'.number_format($value / 100, 2) : '$0.00')
                ->help('Amount in cents'),

            Number::make('Priority')
                ->sortable()
                ->help('Lower numbers are paid off first'),
        ];
    }

    /**
     * Get the cards available for the resource.
     *
     * @return array<int, Card>
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @return array<int, Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [];
    }
}

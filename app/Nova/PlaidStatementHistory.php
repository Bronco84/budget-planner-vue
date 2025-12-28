<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Http\Requests\NovaRequest;

class PlaidStatementHistory extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\PlaidStatementHistory>
     */
    public static $model = \App\Models\PlaidStatementHistory::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'statement_issue_date';

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
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Plaid Account', 'plaidAccount', PlaidAccount::class)
                ->sortable()
                ->required(),

            Number::make('Statement Balance', 'statement_balance_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00')
                ->help('Balance in cents'),

            Date::make('Statement Issue Date', 'statement_issue_date')
                ->sortable()
                ->required(),

            Date::make('Payment Due Date', 'payment_due_date')
                ->sortable(),

            Number::make('Minimum Payment', 'minimum_payment_cents')
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00')
                ->help('Amount in cents'),

            Number::make('APR %', 'apr_percentage')
                ->step(0.01),

            Number::make('Credit Utilization %', 'credit_utilization_percentage')
                ->step(0.01)
                ->help('Percentage of credit limit used'),
        ];
    }

    /**
     * Get the cards available for the resource.
     *
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, \Laravel\Nova\Filters\Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @return array<int, \Laravel\Nova\Lenses\Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @return array<int, \Laravel\Nova\Actions\Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [];
    }
}

<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class PlaidAccount extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\PlaidAccount>
     */
    public static $model = \App\Models\PlaidAccount::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'account_name';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id', 'account_name', 'plaid_account_id', 'account_mask',
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

            BelongsTo::make('Plaid Connection', 'plaidConnection', PlaidConnection::class)
                ->sortable()
                ->required(),

            BelongsTo::make('Account')
                ->sortable()
                ->nullable(),

            Text::make('Plaid Account ID', 'plaid_account_id')
                ->sortable()
                ->required()
                ->readonly(),

            Text::make('Account Name', 'account_name')
                ->sortable()
                ->required(),

            Text::make('Account Type', 'account_type')
                ->sortable(),

            Text::make('Account Subtype', 'account_subtype')
                ->sortable(),

            Text::make('Account Mask', 'account_mask')
                ->sortable()
                ->hideFromIndex(),

            Number::make('Current Balance', 'current_balance_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00')
                ->help('Balance in cents'),

            Number::make('Available Balance', 'available_balance_cents')
                ->hideFromIndex()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00')
                ->help('Balance in cents'),

            DateTime::make('Balance Updated At')
                ->hideFromIndex(),

            Number::make('Last Statement Balance', 'last_statement_balance_cents')
                ->hideFromIndex()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00')
                ->help('For credit cards - balance in cents'),

            Date::make('Last Statement Issue Date', 'last_statement_issue_date')
                ->hideFromIndex(),

            Number::make('Last Payment Amount', 'last_payment_amount_cents')
                ->hideFromIndex()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00'),

            Date::make('Last Payment Date', 'last_payment_date')
                ->hideFromIndex(),

            Date::make('Next Payment Due Date', 'next_payment_due_date')
                ->hideFromIndex(),

            Number::make('Minimum Payment', 'minimum_payment_amount_cents')
                ->hideFromIndex()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00'),

            Number::make('APR %', 'apr_percentage')
                ->hideFromIndex()
                ->step(0.01),

            Number::make('Credit Limit', 'credit_limit_cents')
                ->hideFromIndex()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00'),

            DateTime::make('Liability Updated At', 'liability_updated_at')
                ->hideFromIndex(),

            HasMany::make('Statement History', 'statementHistory', PlaidStatementHistory::class),

            HasMany::make('Holdings', 'holdings', PlaidHolding::class),
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

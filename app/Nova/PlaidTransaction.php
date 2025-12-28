<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\HasOne;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class PlaidTransaction extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\PlaidTransaction>
     */
    public static $model = \App\Models\PlaidTransaction::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id', 'name', 'merchant_name', 'plaid_transaction_id',
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

            BelongsTo::make('Account')
                ->sortable()
                ->nullable(),

            Text::make('Plaid Transaction ID', 'plaid_transaction_id')
                ->sortable()
                ->required()
                ->readonly(),

            Text::make('Plaid Account ID', 'plaid_account_id')
                ->hideFromIndex(),

            Text::make('Name')
                ->sortable()
                ->required(),

            Text::make('Merchant Name', 'merchant_name')
                ->sortable()
                ->nullable(),

            Number::make('Amount')
                ->sortable()
                ->required()
                ->step(0.01),

            Date::make('Date')
                ->sortable()
                ->required(),

            DateTime::make('DateTime')
                ->hideFromIndex(),

            Date::make('Authorized Date', 'authorized_date')
                ->hideFromIndex(),

            Boolean::make('Pending')
                ->sortable(),

            Text::make('Payment Channel', 'payment_channel')
                ->hideFromIndex(),

            Text::make('Transaction Type', 'transaction_type')
                ->hideFromIndex(),

            Text::make('Category')
                ->hideFromIndex(),

            HasOne::make('Transaction'),
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

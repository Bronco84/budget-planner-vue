<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class PlaidSecurity extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\PlaidSecurity>
     */
    public static $model = \App\Models\PlaidSecurity::class;

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
        'id', 'ticker_symbol', 'name', 'plaid_security_id',
    ];

    /**
     * Get the displayable label of the resource.
     */
    public static function label(): string
    {
        return 'Securities';
    }

    /**
     * Get the displayable singular label of the resource.
     */
    public static function singularLabel(): string
    {
        return 'Security';
    }

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Ticker', 'ticker_symbol')
                ->sortable(),

            Text::make('Name')
                ->sortable(),

            Text::make('Type')
                ->sortable(),

            Number::make('Close Price', 'close_price_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '-'),

            Date::make('Price As Of', 'close_price_as_of')
                ->sortable(),

            Text::make('ISIN', 'isin')
                ->hideFromIndex(),

            Text::make('CUSIP', 'cusip')
                ->hideFromIndex(),

            Text::make('SEDOL', 'sedol')
                ->hideFromIndex(),

            Text::make('Currency', 'iso_currency_code')
                ->hideFromIndex(),

            Boolean::make('Cash Equivalent', 'is_cash_equivalent')
                ->hideFromIndex(),

            Text::make('Plaid Security ID', 'plaid_security_id')
                ->hideFromIndex()
                ->readonly(),

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

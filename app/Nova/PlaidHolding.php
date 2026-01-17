<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class PlaidHolding extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\PlaidHolding>
     */
    public static $model = \App\Models\PlaidHolding::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public function title()
    {
        return $this->security?->ticker_symbol ?? $this->security?->name ?? 'Holding #' . $this->id;
    }

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id', 'plaid_account_identifier',
    ];

    /**
     * Get the displayable label of the resource.
     */
    public static function label(): string
    {
        return 'Holdings';
    }

    /**
     * Get the displayable singular label of the resource.
     */
    public static function singularLabel(): string
    {
        return 'Holding';
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

            BelongsTo::make('Account', 'plaidAccount', PlaidAccount::class)
                ->sortable(),

            BelongsTo::make('Security', 'security', PlaidSecurity::class)
                ->sortable(),

            Number::make('Quantity')
                ->sortable()
                ->step(0.00000001)
                ->displayUsing(fn ($value) => number_format($value, 4)),

            Number::make('Cost Basis', 'cost_basis_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '-'),

            Number::make('Current Price', 'institution_price_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '-'),

            Number::make('Market Value', 'institution_value_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '-'),

            // Computed gain/loss field
            Number::make('Gain/Loss', function () {
                if ($this->institution_value_cents === null || $this->cost_basis_cents === null) {
                    return null;
                }
                return $this->institution_value_cents - $this->cost_basis_cents;
            })
                ->sortable()
                ->displayUsing(function ($value) {
                    if ($value === null) return '-';
                    $formatted = '$' . number_format(abs($value) / 100, 2);
                    return $value >= 0 ? "+{$formatted}" : "-{$formatted}";
                }),

            Date::make('Price As Of', 'institution_price_as_of')
                ->hideFromIndex(),

            Text::make('Currency', 'iso_currency_code')
                ->hideFromIndex(),

            Text::make('Plaid Account ID', 'plaid_account_identifier')
                ->hideFromIndex()
                ->readonly(),
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

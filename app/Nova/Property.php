<?php

namespace App\Nova;

use App\Models\Property as PropertyModel;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class Property extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\Property>
     */
    public static $model = \App\Models\Property::class;

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
        'id', 'name', 'address', 'vehicle_make', 'vehicle_model', 'vin',
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

            BelongsTo::make('Budget')
                ->sortable()
                ->required(),

            Text::make('Name')
                ->sortable()
                ->required()
                ->rules('required', 'max:255'),

            Select::make('Type')
                ->options([
                    'property' => 'Property',
                    'vehicle' => 'Vehicle',
                    'other' => 'Other',
                ])
                ->displayUsingLabels()
                ->sortable()
                ->required(),

            Number::make('Current Value', 'current_value_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00')
                ->help('Value in cents'),

            DateTime::make('Value Updated At', 'value_updated_at')
                ->hideFromIndex(),

            Text::make('Address')
                ->hideFromIndex()
                ->nullable(),

            Select::make('Property Type', 'property_type')
                ->options(PropertyModel::PROPERTY_TYPES)
                ->displayUsingLabels()
                ->hideFromIndex()
                ->nullable(),

            Number::make('Bedrooms')
                ->hideFromIndex()
                ->nullable(),

            Number::make('Bathrooms')
                ->hideFromIndex()
                ->nullable(),

            Number::make('Square Feet', 'square_feet')
                ->hideFromIndex()
                ->nullable(),

            Number::make('Year Built', 'year_built')
                ->hideFromIndex()
                ->nullable(),

            Text::make('Vehicle Make', 'vehicle_make')
                ->hideFromIndex()
                ->nullable(),

            Text::make('Vehicle Model', 'vehicle_model')
                ->hideFromIndex()
                ->nullable(),

            Number::make('Vehicle Year', 'vehicle_year')
                ->hideFromIndex()
                ->nullable(),

            Text::make('VIN')
                ->hideFromIndex()
                ->nullable(),

            Number::make('Mileage')
                ->hideFromIndex()
                ->nullable(),

            Textarea::make('Notes')
                ->hideFromIndex()
                ->nullable(),

            HasMany::make('Linked Accounts', 'linkedAccounts', Account::class),
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

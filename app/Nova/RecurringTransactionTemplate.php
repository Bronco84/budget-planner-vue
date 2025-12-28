<?php

namespace App\Nova;

use App\Models\RecurringTransactionTemplate as RTTModel;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class RecurringTransactionTemplate extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\RecurringTransactionTemplate>
     */
    public static $model = \App\Models\RecurringTransactionTemplate::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'description';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id', 'description', 'category',
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

            BelongsTo::make('Account')
                ->sortable()
                ->required(),

            Text::make('Description')
                ->sortable()
                ->required(),

            Text::make('Category')
                ->sortable()
                ->nullable(),

            Number::make('Amount', 'amount_in_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00')
                ->help('Amount in cents'),

            Select::make('Frequency')
                ->options(RTTModel::getFrequencyOptions())
                ->displayUsingLabels()
                ->sortable()
                ->required(),

            Date::make('Start Date', 'start_date')
                ->sortable()
                ->required(),

            Date::make('End Date', 'end_date')
                ->sortable()
                ->nullable(),

            Boolean::make('Is Dynamic Amount', 'is_dynamic_amount')
                ->hideFromIndex(),

            Textarea::make('Notes')
                ->hideFromIndex()
                ->nullable(),

            HasMany::make('Transactions'),

            HasMany::make('Rules', 'rules', RecurringTransactionRule::class),
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

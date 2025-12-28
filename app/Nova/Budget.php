<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class Budget extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\Budget>
     */
    public static $model = \App\Models\Budget::class;

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
        'id', 'name', 'description',
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

            BelongsTo::make('User')
                ->sortable()
                ->required(),

            Text::make('Name')
                ->sortable()
                ->required()
                ->rules('required', 'max:255'),

            Textarea::make('Description')
                ->nullable()
                ->hideFromIndex(),

            BelongsTo::make('Starting Balance Account', 'startingBalanceAccount', Account::class)
                ->nullable()
                ->hideFromIndex(),

            HasMany::make('Accounts'),

            HasMany::make('Transactions'),

            HasMany::make('Categories'),

            HasMany::make('Payoff Plans', 'payoffPlans', PayoffPlan::class),

            HasMany::make('Properties'),

            HasMany::make('Recurring Transaction Templates', 'recurringTransactionTemplates', RecurringTransactionTemplate::class),

            BelongsToMany::make('Connected Users', 'connected_users', User::class),
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

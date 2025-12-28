<?php

namespace App\Nova;

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

class PayoffPlan extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\PayoffPlan>
     */
    public static $model = \App\Models\PayoffPlan::class;

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

            BelongsTo::make('Budget')
                ->sortable()
                ->required(),

            Text::make('Name')
                ->sortable()
                ->required()
                ->rules('required', 'max:255'),

            Textarea::make('Description')
                ->nullable()
                ->hideFromIndex(),

            Select::make('Strategy')
                ->options([
                    'avalanche' => 'Avalanche (Highest Interest First)',
                    'snowball' => 'Snowball (Smallest Balance First)',
                    'custom' => 'Custom Order',
                ])
                ->displayUsingLabels()
                ->sortable()
                ->required(),

            Number::make('Monthly Extra Payment', 'monthly_extra_payment_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00')
                ->help('Extra payment amount in cents'),

            Boolean::make('Is Active', 'is_active')
                ->sortable(),

            Date::make('Start Date', 'start_date')
                ->sortable()
                ->required(),

            HasMany::make('Debts', 'debts', PayoffPlanDebt::class),

            HasMany::make('Goals', 'goals', PayoffPlanGoal::class),

            HasMany::make('Snapshots', 'snapshots', PayoffPlanSnapshot::class),
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

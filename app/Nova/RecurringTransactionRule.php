<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class RecurringTransactionRule extends Resource
{
    public static $model = \App\Models\RecurringTransactionRule::class;
    public static $title = 'field';
    public static $search = ['id', 'field', 'value'];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('Recurring Transaction Template', 'recurringTransactionTemplate', RecurringTransactionTemplate::class)->sortable()->required(),
            Text::make('Field')->sortable()->required(),
            Text::make('Operator')->sortable()->required(),
            Text::make('Value')->sortable(),
            Boolean::make('Is Case Sensitive', 'is_case_sensitive'),
            Number::make('Priority')->sortable(),
            Boolean::make('Is Active', 'is_active')->sortable(),
        ];
    }

    public function cards(NovaRequest $request): array { return []; }
    public function filters(NovaRequest $request): array { return []; }
    public function lenses(NovaRequest $request): array { return []; }
    public function actions(NovaRequest $request): array { return []; }
}

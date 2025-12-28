<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class Transaction extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\Transaction>
     */
    public static $model = \App\Models\Transaction::class;

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
        'id', 'description', 'category', 'notes',
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
                ->required()
                ->rules('required', 'max:255'),

            Text::make('Category')
                ->sortable()
                ->nullable(),

            Number::make('Amount', 'amount_in_cents')
                ->sortable()
                ->required()
                ->displayUsing(fn ($value) => $value ? '$' . number_format($value / 100, 2) : '$0.00')
                ->help('Amount in cents (negative for expenses, positive for income)'),

            Date::make('Date')
                ->sortable()
                ->required(),

            Text::make('Import Source')
                ->sortable()
                ->hideFromIndex()
                ->default('manual'),

            Boolean::make('Plaid Imported', 'is_plaid_imported')
                ->hideFromIndex(),

            Boolean::make('Reconciled', 'is_reconciled')
                ->sortable(),

            Textarea::make('Notes')
                ->nullable()
                ->hideFromIndex(),

            BelongsTo::make('Plaid Transaction', 'plaidTransaction', PlaidTransaction::class)
                ->nullable()
                ->hideFromIndex(),

            BelongsTo::make('Recurring Template', 'recurringTemplate', RecurringTransactionTemplate::class)
                ->nullable()
                ->hideFromIndex(),

            MorphMany::make('File Attachments', 'fileAttachments', FileAttachment::class),
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

<?php

namespace App\Nova;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Card;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;

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
     * Transfer legs are kept in sync by TransferService, so, as in the app, a leg
     * cannot be deleted on its own (that would leave the transfer and its other
     * leg behind). Delete the transfer in the app instead. Other transactions are
     * deleted by Nova; the model's deleting hook removes their attachments.
     *
     * @param  \App\Models\Transaction  $model
     *
     * @throws ValidationException
     */
    public static function beforeDelete(NovaRequest $request, Model $model): void
    {
        if ($model->isTransfer()) {
            throw ValidationException::withMessages([
                'resources' => 'This transaction is part of a transfer. Delete the transfer instead.',
            ]);
        }
    }

    /**
     * Transfer legs are not edited on their own either: TransferService keeps both
     * legs and the transfer in step, and the app sends leg edits to the transfer.
     * Edit the transfer in the app instead. This also stops replicating a leg.
     */
    public function authorizedToUpdate(Request $request): bool
    {
        return ! $this->isTransferLeg() && parent::authorizedToUpdate($request);
    }

    /**
     * Nova's update endpoints call this rather than authorizedToUpdate.
     *
     * @throws AuthorizationException
     */
    public function authorizeToUpdate(Request $request): void
    {
        if ($this->isTransferLeg()) {
            throw new AuthorizationException('This transaction is part of a transfer. Edit the transfer instead.');
        }

        parent::authorizeToUpdate($request);
    }

    private function isTransferLeg(): bool
    {
        return $this->resource instanceof \App\Models\Transaction && $this->resource->isTransfer();
    }

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, Field>
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
                ->displayUsing(fn ($value) => $value ? '$'.number_format($value / 100, 2) : '$0.00')
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
     * @return array<int, Card>
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @return array<int, Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [];
    }
}

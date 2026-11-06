<?php

namespace App\Nova;

use App\Models\User;
use App\Services\PlaidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Card;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\HasOne;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;

class Account extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\Account>
     */
    public static $model = \App\Models\Account::class;

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
        'id', 'name', 'type',
    ];

    /**
     * Apply the app's delete guards (no transfers, no transactions) and, as the app
     * does, remove the Plaid connection and its Item when this was the connection's
     * last account. Nova's own delete() that follows is a no-op on the deleted model.
     *
     * @param  \App\Models\Account  $model
     *
     * @throws ValidationException
     */
    public static function beforeDelete(NovaRequest $request, Model $model): void
    {
        if ($reason = $model->deletionBlockedReason()) {
            throw ValidationException::withMessages(['resources' => $reason]);
        }

        $plaidConnection = $model->plaidAccount?->plaidConnection;

        $model->delete();

        if ($plaidConnection) {
            app(PlaidService::class)->deleteConnectionIfEmpty($plaidConnection);
        }
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

            Text::make('Name')
                ->sortable()
                ->required()
                ->rules('required', 'max:255'),

            Select::make('Type')
                ->options(User::ACCOUNT_TYPES)
                ->displayUsingLabels()
                ->sortable()
                ->required(),

            Number::make('Current Balance', 'current_balance_cents')
                ->sortable()
                ->displayUsing(fn ($value) => $value ? '$'.number_format($value / 100, 2) : '$0.00')
                ->help('Balance in cents'),

            DateTime::make('Balance Updated At')
                ->sortable()
                ->hideFromIndex(),

            Boolean::make('Include in Budget', 'include_in_budget')
                ->sortable(),

            Boolean::make('Exclude from Total Balance', 'exclude_from_total_balance')
                ->hideFromIndex(),

            Boolean::make('Autopay Enabled', 'autopay_enabled')
                ->hideFromIndex(),

            BelongsTo::make('Autopay Source Account', 'autopaySourceAccount', Account::class)
                ->nullable()
                ->hideFromIndex(),

            Number::make('Autopay Amount Override', 'autopay_amount_override_cents')
                ->nullable()
                ->hideFromIndex()
                ->help('Amount in cents'),

            BelongsTo::make('Property')
                ->nullable()
                ->hideFromIndex(),

            HasOne::make('Plaid Account', 'plaidAccount', PlaidAccount::class),

            HasMany::make('Transactions'),

            HasMany::make('Autopay Target Accounts', 'autopayTargetAccounts', Account::class),
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

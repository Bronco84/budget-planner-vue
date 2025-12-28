<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class PlaidConnection extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\PlaidConnection>
     */
    public static $model = \App\Models\PlaidConnection::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'institution_name';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id', 'institution_name', 'plaid_item_id',
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

            Text::make('Plaid Item ID', 'plaid_item_id')
                ->sortable()
                ->required()
                ->readonly(),

            Text::make('Institution ID', 'institution_id')
                ->hideFromIndex(),

            Text::make('Institution Name', 'institution_name')
                ->sortable()
                ->required(),

            Text::make('Institution Logo', 'institution_logo')
                ->hideFromIndex(),

            Text::make('Institution URL', 'institution_url')
                ->hideFromIndex(),

            Text::make('Access Token', 'access_token')
                ->onlyOnForms()
                ->help('Encrypted Plaid access token'),

            Select::make('Status')
                ->options([
                    'active' => 'Active',
                    'error' => 'Error',
                    'disconnected' => 'Disconnected',
                    'expired' => 'Expired',
                ])
                ->displayUsingLabels()
                ->sortable(),

            Textarea::make('Error Message', 'error_message')
                ->nullable()
                ->hideFromIndex(),

            DateTime::make('Last Sync At', 'last_sync_at')
                ->sortable()
                ->hideFromIndex(),

            HasMany::make('Plaid Accounts', 'plaidAccounts', PlaidAccount::class),
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

<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class FlaggedChatMessage extends Resource
{
    public static $model = \App\Models\FlaggedChatMessage::class;
    public static $title = 'flag_type';
    public static $search = ['id', 'flag_type'];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('User')->sortable()->required(),
            BelongsTo::make('Conversation', 'conversation', ChatConversation::class)->sortable(),
            BelongsTo::make('Message', 'message', ChatMessage::class)->sortable(),
            Text::make('Flag Type', 'flag_type')->sortable()->required(),
            Textarea::make('Content')->hideFromIndex(),
            Code::make('Metadata')->json()->hideFromIndex(),
            Boolean::make('Reviewed')->sortable(),
            Text::make('Action Taken', 'action_taken')->hideFromIndex(),
        ];
    }

    public function cards(NovaRequest $request): array { return []; }
    public function filters(NovaRequest $request): array { return []; }
    public function lenses(NovaRequest $request): array { return []; }
    public function actions(NovaRequest $request): array { return []; }
}

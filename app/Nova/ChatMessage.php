<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ChatMessage extends Resource
{
    public static $model = \App\Models\ChatMessage::class;
    public static $title = 'id';
    public static $search = ['id', 'content'];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('Conversation', 'conversation', ChatConversation::class)->sortable()->required(),
            Select::make('Role')->options([
                'user' => 'User',
                'assistant' => 'Assistant',
                'system' => 'System',
            ])->displayUsingLabels()->sortable()->required(),
            Textarea::make('Content')->required(),
            Code::make('Metadata')->json()->hideFromIndex(),
        ];
    }

    public function cards(NovaRequest $request): array { return []; }
    public function filters(NovaRequest $request): array { return []; }
    public function lenses(NovaRequest $request): array { return []; }
    public function actions(NovaRequest $request): array { return []; }
}

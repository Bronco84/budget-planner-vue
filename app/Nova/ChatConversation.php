<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class ChatConversation extends Resource
{
    public static $model = \App\Models\ChatConversation::class;
    public static $title = 'title';
    public static $search = ['id', 'title'];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('User')->sortable()->required(),
            Text::make('Title')->sortable()->required(),
            HasMany::make('Messages', 'messages', ChatMessage::class),
        ];
    }

    public function cards(NovaRequest $request): array { return []; }
    public function filters(NovaRequest $request): array { return []; }
    public function lenses(NovaRequest $request): array { return []; }
    public function actions(NovaRequest $request): array { return []; }
}

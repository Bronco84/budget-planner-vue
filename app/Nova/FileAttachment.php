<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class FileAttachment extends Resource
{
    public static $model = \App\Models\FileAttachment::class;
    public static $title = 'id';
    public static $search = ['id'];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('File')->sortable()->required(),
            MorphTo::make('Attachable')->types([
                Transaction::class,
                Budget::class,
            ]),
            BelongsTo::make('Attached By', 'attachedBy', User::class)->nullable(),
            Textarea::make('Description')->nullable()->hideFromIndex(),
        ];
    }

    public function cards(NovaRequest $request): array { return []; }
    public function filters(NovaRequest $request): array { return []; }
    public function lenses(NovaRequest $request): array { return []; }
    public function actions(NovaRequest $request): array { return []; }
}

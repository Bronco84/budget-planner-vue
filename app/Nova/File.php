<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class File extends Resource
{
    public static $model = \App\Models\File::class;
    public static $title = 'original_name';
    public static $search = ['id', 'original_name', 'hash'];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Original Name', 'original_name')->sortable()->required(),
            Text::make('Hash')->sortable()->readonly(),
            Text::make('MIME Type', 'mime_type')->sortable(),
            Text::make('Extension')->sortable(),
            Number::make('Size (bytes)', 'size_bytes')->sortable(),
            BelongsTo::make('Uploaded By', 'uploader', User::class)->nullable(),
            HasMany::make('Attachments', 'attachments', FileAttachment::class),
        ];
    }

    public function cards(NovaRequest $request): array { return []; }
    public function filters(NovaRequest $request): array { return []; }
    public function lenses(NovaRequest $request): array { return []; }
    public function actions(NovaRequest $request): array { return []; }
}

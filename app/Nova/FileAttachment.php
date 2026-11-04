<?php

namespace App\Nova;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class FileAttachment extends Resource
{
    public static $model = \App\Models\FileAttachment::class;

    public static $title = 'id';

    public static $search = ['id'];

    /**
     * Detach through FileService, as the app does, so a file nothing else uses is
     * removed from S3 and the database. Nova's own delete() that follows is a no-op.
     *
     * @param  \App\Models\FileAttachment  $model
     */
    public static function beforeDelete(NovaRequest $request, Model $model): void
    {
        app(FileService::class)->detachFile($model, $request->user()->id);
    }

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

    public function cards(NovaRequest $request): array
    {
        return [];
    }

    public function filters(NovaRequest $request): array
    {
        return [];
    }

    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    public function actions(NovaRequest $request): array
    {
        return [];
    }
}

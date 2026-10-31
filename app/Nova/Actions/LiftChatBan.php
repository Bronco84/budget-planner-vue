<?php

namespace App\Nova\Actions;

use App\Models\FlaggedChatMessage;
use App\Models\User;
use App\Services\ContentModerationService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

/**
 * Lift a chat ban (and clear the offense history behind it) for the selected
 * users, or for the authors of the selected flagged chat messages.
 */
class LiftChatBan extends Action
{
    public $name = 'Lift Chat Ban';

    public $confirmText = 'Lift the chat ban and clear the offense history for the selected user(s)?';

    /**
     * @param  Collection<int, User|FlaggedChatMessage>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $moderation = app(ContentModerationService::class);

        $models
            ->map(fn ($model) => $model instanceof FlaggedChatMessage ? $model->user : $model)
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->each(fn (User $user) => $moderation->liftBan($user));

        return Action::message('Chat ban lifted.');
    }
}

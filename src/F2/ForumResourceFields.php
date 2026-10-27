<?php

namespace Neoncube\FlarumPrivateMessages\F2;

use Flarum\Api\Context;
use Flarum\Api\Schema;

class ForumResourceFields
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('canMessage')
                ->get(function (object $model, Context $context) {
                    return $context->getActor()->can('startConversation');
                }),
            Schema\Boolean::make('neoncubePrivateMessagesAllowUsersToReceiveEmailNotifications')
                ->get(function (object $model, Context $context) {
                    return $context->getActor()->can('neoncube-private-messages.allowUsersToReceiveEmailNotifications');
                }),
        ];
    }
}

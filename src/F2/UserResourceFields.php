<?php

namespace Neoncube\FlarumPrivateMessages\F2;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;

class UserResourceFields
{
    public function __invoke(): array
    {
        return [
            Schema\Integer::make('unreadMessages')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id)
                ->get(function (User $user, Context $context) {
                    return (int) ($user->unread_messages ?? 0);
                }),
        ];
    }
}

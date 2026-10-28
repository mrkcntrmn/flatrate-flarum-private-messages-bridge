<?php

namespace Neoncube\FlarumPrivateMessages\F2;

use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Neoncube\FlarumPrivateMessages\Message;
use Tobyz\JsonApiServer\Context;

/**
 * Flarum 2 JSON:API mapping for DM Message models used as notification subjects.
 *
 * Registration exists so NotificationResource::subjectTypes() can resolve
 * Message::class to a type string. endpoints() is empty on purpose: private
 * messages stay on neoncube-private-messages/* controllers and must not gain
 * a public /api/{type} CRUD surface.
 *
 * @extends AbstractDatabaseResource<Message>
 */
class MessageResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'neoncube-private-messages-messages';
    }

    public function model(): string
    {
        return Message::class;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function scope(Builder $query, Context $context): void
    {
        $actor = $context->getActor();

        if ($actor->isGuest()) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereIn('conversation_id', function ($sub) use ($actor) {
            $sub->select('conversation_id')
                ->from('conversation_user')
                ->where('user_id', $actor->id);
        });
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('message')
                ->get(fn (Message $message) => $message->is_hidden ? '' : (string) $message->message),
            Schema\Integer::make('userId'),
            Schema\Boolean::make('isHidden'),
            Schema\DateTime::make('createdAt'),
            Schema\Integer::make('conversationId'),
            Schema\Integer::make('number'),
        ];
    }
}

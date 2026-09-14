<?php

namespace Neoncube\FlarumPrivateMessages\Api\Serializers;

use Flarum\Api\Serializer\AbstractSerializer;
use Neoncube\FlarumPrivateMessages\Conversation;
use Neoncube\FlarumPrivateMessages\ConversationUnreadAccounting;

class ConversationSerializer extends AbstractSerializer
{
    protected $type = 'conversations';

    protected function getDefaultAttributes($conversation)
    {
        if (!($conversation instanceof Conversation)) {
            throw new \InvalidArgumentException(
                get_class($this) . ' can only serialize instances of ' . Conversation::class
            );
        }

        return [
            'status' => json_decode($conversation->status ?? 'null'),
            'createdAt' => $this->formatDate($conversation->created_at),
            'updatedAt' => $this->formatDate($conversation->updated_at),
            'totalMessages' => $conversation->total_messages,
            'notNew' => (bool) $conversation->notNew,
            'unReadCount' => ConversationUnreadAccounting::unreadCountForActor(
                $conversation,
                $this->getActor()
            ),
        ];
    }

    protected function messages($conversation)
    {
        return $this->hasMany($conversation, MessageSerializer::class);
    }

    protected function recipients($conversation)
    {
        return $this->hasMany($conversation, ConversationRecipientSerializer::class);
    }
}
<?php

namespace Neoncube\FlarumPrivateMessages\F2;

use Neoncube\FlarumPrivateMessages\Message;

/**
 * Minimal JSON:API message documents for Flarum 2 handlers.
 * Attribute names match js/src/forum/models/Message.js (message, not content).
 */
final class MessageJson
{
    public static function resource(Message $message): array
    {
        $hidden = !empty($message->is_hidden);

        return [
            'type' => 'messages',
            'id' => (string) $message->id,
            'attributes' => [
                'message' => $hidden ? '' : (string) $message->message,
                'userId' => (int) $message->user_id,
                'isHidden' => (bool) $hidden,
                'createdAt' => self::formatDate($message->created_at),
                'conversationId' => (int) $message->conversation_id,
                'number' => (int) $message->number,
            ],
            'relationships' => [
                'user' => [
                    'data' => [
                        'type' => 'users',
                        'id' => (string) $message->user_id,
                    ],
                ],
            ],
        ];
    }

    public static function includedUser($user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'type' => 'users',
            'id' => (string) $user->id,
            'attributes' => [
                'username' => (string) $user->username,
                'displayName' => (string) ($user->display_name ?? $user->username),
                'avatarUrl' => $user->avatar_url ?? null,
            ],
        ];
    }

    private static function formatDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return (string) $value;
    }
}

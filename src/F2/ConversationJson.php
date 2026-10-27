<?php

namespace Neoncube\FlarumPrivateMessages\F2;

use Neoncube\FlarumPrivateMessages\Conversation;
use Neoncube\FlarumPrivateMessages\ConversationUnreadAccounting;
use Flarum\User\User;

/**
 * Minimal JSON:API conversation documents for Flarum 2 handlers.
 * Includes recipients + users so Direct UI can resolve peer identity.
 */
final class ConversationJson
{
    public static function document(Conversation $conversation, User $actor): array
    {
        $conversation->loadMissing(['recipients.user']);

        $recipientData = [];
        $included = [];
        $seenUsers = [];

        foreach ($conversation->recipients as $recipient) {
            $recipientData[] = [
                'type' => 'conversation_users',
                'id' => (string) $recipient->id,
            ];
            $included[] = [
                'type' => 'conversation_users',
                'id' => (string) $recipient->id,
                'attributes' => [
                    'userId' => (int) $recipient->user_id,
                    'conversationId' => (int) $recipient->conversation_id,
                    'lastRead' => (int) ($recipient->last_read_message_number ?? 0),
                ],
                'relationships' => [
                    'user' => [
                        'data' => [
                            'type' => 'users',
                            'id' => (string) $recipient->user_id,
                        ],
                    ],
                ],
            ];

            $userId = (string) $recipient->user_id;
            if ($userId !== '' && !isset($seenUsers[$userId])) {
                $userDoc = MessageJson::includedUser($recipient->user);
                if ($userDoc !== null) {
                    $included[] = $userDoc;
                    $seenUsers[$userId] = true;
                }
            }
        }

        return [
            'data' => [
                'type' => 'conversations',
                'id' => (string) $conversation->id,
                'attributes' => [
                    'createdAt' => self::formatDate($conversation->created_at),
                    'updatedAt' => self::formatDate($conversation->updated_at),
                    'totalMessages' => (int) $conversation->total_messages,
                    'notNew' => (bool) ($conversation->notNew ?? false),
                    'unReadCount' => (int) ConversationUnreadAccounting::unreadCountForActor($conversation, $actor),
                ],
                'relationships' => [
                    'recipients' => [
                        'data' => $recipientData,
                    ],
                ],
            ],
            'included' => $included,
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

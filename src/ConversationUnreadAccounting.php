<?php

namespace Neoncube\FlarumPrivateMessages;

/**
 * Actor-correct unread math and conversation activity clock.
 *
 * Authoritative read state is conversation_users.last_read_message_number.
 * Incoming unread never includes the actor's own messages and never uses
 * messages.is_seen. Query helpers call count() on constrained builders —
 * they must not get()+filter in PHP.
 *
 * Activity sorting uses Conversation::$timestamps / updated_at. Laravel
 * Model::increment() updates updated_at when timestamps are true, so
 * NewMessageHandler must not add a redundant touch().
 */
class ConversationUnreadAccounting
{
    /**
     * Conversation list/activity clock. Serializer formats this field, not created_at.
     *
     * @param object $conversation
     * @return mixed
     */
    public static function activityAt($conversation)
    {
        return $conversation->updated_at ?? null;
    }

    /**
     * Unread incoming messages for the requesting actor in one conversation.
     *
     * @param object $conversation Conversation with recipients() and messages() relations
     * @param object|null $actor
     */
    public static function unreadCountForActor($conversation, $actor): int
    {
        if (!$actor || (method_exists($actor, 'isGuest') && $actor->isGuest())) {
            return 0;
        }

        $conversationUser = $conversation->recipients()->where('user_id', $actor->id)->first();
        $lastRead = $conversationUser ? (int) $conversationUser->last_read_message_number : 0;

        return static::unreadIncomingCount(
            $conversation->messages(),
            $lastRead,
            (int) $actor->id
        );
    }

    /**
     * Database unread: number > lastRead AND user_id != actorId, then count().
     *
     * @param object $messagesQuery Eloquent-like builder
     */
    public static function unreadIncomingCount($messagesQuery, int $lastRead, int $actorId): int
    {
        return $messagesQuery
            ->where('number', '>', $lastRead)
            ->where('user_id', '!=', $actorId)
            ->count();
    }

    /**
     * In-memory unread for isolated unit tests.
     *
     * @param array<int, array{number:int|string, user_id:int|string}> $messages
     */
    public static function countUnreadIncoming(array $messages, int $lastRead, int $actorId): int
    {
        $count = 0;
        foreach ($messages as $message) {
            $number = (int) $message['number'];
            $userId = (int) $message['user_id'];
            if ($number > $lastRead && $userId !== $actorId) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Incoming messages consumed by advancing last-read in this conversation.
     *
     * Count where conversation_id matches, number > oldRead, number <= newlyRead,
     * and user_id != actor. Already-read (newlyRead <= oldRead) returns 0.
     *
     * @param int|string $conversationId
     * @param int|string $actorId
     * @param int|string|null $oldRead
     * @param int|string|null $newlyRead
     * @param object $messageQuery Message query (may already be scoped to the conversation)
     */
    public static function consumedIncoming($conversationId, $actorId, $oldRead, $newlyRead, $messageQuery): int
    {
        $oldRead = (int) $oldRead;
        $newlyRead = (int) $newlyRead;
        if ($newlyRead <= $oldRead) {
            return 0;
        }

        return $messageQuery
            ->where('conversation_id', $conversationId)
            ->where('number', '>', $oldRead)
            ->where('number', '<=', $newlyRead)
            ->where('user_id', '!=', $actorId)
            ->count();
    }

    /**
     * In-memory consumed-incoming count for isolated unit tests.
     *
     * @param array<int, array{number:int|string, user_id:int|string}> $messages
     */
    public static function countConsumedIncoming(array $messages, int $oldRead, int $newlyRead, int $actorId): int
    {
        if ($newlyRead <= $oldRead) {
            return 0;
        }

        $count = 0;
        foreach ($messages as $message) {
            $number = (int) $message['number'];
            $userId = (int) $message['user_id'];
            if ($number > $oldRead && $number <= $newlyRead && $userId !== $actorId) {
                $count++;
            }
        }

        return $count;
    }
}

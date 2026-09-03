<?php

namespace Neoncube\FlarumPrivateMessages;

use Flarum\User\User;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Server-side 1:1 recipient and message body validation.
 */
class ConversationValidator
{
    /** @var callable */
    protected $userFinder;

    public function __construct(?callable $userFinder = null)
    {
        $this->userFinder = $userFinder ?: function ($id) {
            return User::find($id);
        };
    }

    /**
     * Accept exactly one scalar recipient user id (not the actor).
     *
     * @param mixed $recipient
     * @throws InvalidArgumentException
     */
    public function normalizeRecipientId($recipient, User $actor): int
    {
        if (is_array($recipient) || is_object($recipient) || is_bool($recipient) || $recipient === null) {
            throw new InvalidArgumentException('Recipient must be a single user id.');
        }

        if (is_string($recipient)) {
            $recipient = trim($recipient);
            if ($recipient === '' || !ctype_digit($recipient)) {
                throw new InvalidArgumentException('Recipient must be a single user id.');
            }
            $recipientId = (int) $recipient;
        } elseif (is_int($recipient)) {
            $recipientId = $recipient;
        } elseif (is_float($recipient)) {
            throw new InvalidArgumentException('Recipient must be a single user id.');
        } else {
            throw new InvalidArgumentException('Recipient must be a single user id.');
        }

        if ($recipientId <= 0) {
            throw new InvalidArgumentException('Recipient must be a single user id.');
        }

        if ($recipientId === (int) $actor->id) {
            throw new InvalidArgumentException('Cannot start a conversation with yourself.');
        }

        $user = call_user_func($this->userFinder, $recipientId);
        if (!$user) {
            throw new InvalidArgumentException('Recipient does not exist.');
        }

        return $recipientId;
    }

    /**
     * @param mixed $messageContents
     * @throws InvalidArgumentException
     */
    public function normalizeMessageContents($messageContents): string
    {
        if (!is_string($messageContents)) {
            throw new InvalidArgumentException('Message contents must be a non-empty string.');
        }

        if (trim($messageContents) === '') {
            throw new InvalidArgumentException('Message contents must be a non-empty string.');
        }

        return $messageContents;
    }

    /**
     * Extract message contents from a JSON:API-ish command payload.
     *
     * @throws InvalidArgumentException
     */
    public function messageContentsFromData(array $data): string
    {
        return $this->normalizeMessageContents(Arr::get($data, 'attributes.messageContents'));
    }

    /**
     * Find an existing exact 1:1 conversation between actor and recipient.
     * Does not treat A+B+C (or other malformed sets) as a reusable A/B thread.
     */
    public function findExactOneToOneConversation(User $actor, int $recipientId): ?Conversation
    {
        $actorId = (int) $actor->id;

        $candidateIds = ConversationUser::where('user_id', $actorId)
            ->pluck('conversation_id')
            ->all();

        foreach ($candidateIds as $conversationId) {
            $conversation = Conversation::find($conversationId);
            if (!$conversation) {
                continue;
            }

            $participantIds = $conversation->recipients()
                ->pluck('user_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->unique()
                ->sort()
                ->values()
                ->all();

            $expected = [$actorId, $recipientId];
            sort($expected);

            if ($participantIds === $expected) {
                return $conversation;
            }
        }

        return null;
    }
}

<?php

namespace Neoncube\FlarumPrivateMessages;

use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Canonical participant authorization for private conversations.
 *
 * Membership must be decided with an existence/first assertion, never by
 * treating an Eloquent Collection as a boolean.
 */
class ConversationAccess
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
     * @throws PermissionDeniedException
     */
    public function assertParticipant(User $actor, Conversation $conversation): void
    {
        if ($actor->isGuest()) {
            throw new PermissionDeniedException;
        }

        if (!$conversation->recipients()->where('user_id', $actor->id)->exists()) {
            throw new PermissionDeniedException;
        }
    }

    /**
     * Resolve a conversation by id and prove the actor is a participant.
     *
     * @throws ModelNotFoundException
     * @throws PermissionDeniedException
     */
    public function assertParticipantInConversationId(User $actor, $conversationId): Conversation
    {
        $conversation = Conversation::findOrFail($conversationId);
        $this->assertParticipant($actor, $conversation);

        return $conversation;
    }

    /**
     * Peer for a 1:1 conversation (the single other participant).
     *
     * @throws PermissionDeniedException when the conversation is not exactly two participants
     *                                   or the peer cannot be determined.
     */
    public function requireOneToOnePeer(User $actor, Conversation $conversation): User
    {
        $this->assertParticipant($actor, $conversation);

        $recipientIds = $conversation->recipients()->pluck('user_id')->map(function ($id) {
            return (int) $id;
        })->unique()->values()->all();

        if (count($recipientIds) !== 2 || !in_array((int) $actor->id, $recipientIds, true)) {
            throw new PermissionDeniedException;
        }

        $peerId = $recipientIds[0] === (int) $actor->id ? $recipientIds[1] : $recipientIds[0];
        $peer = call_user_func($this->userFinder, $peerId);

        if (!$peer) {
            throw new PermissionDeniedException;
        }

        return $peer;
    }
}

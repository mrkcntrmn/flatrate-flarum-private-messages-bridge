<?php

namespace Neoncube\FlarumPrivateMessages\Commands;

use Flarum\User\Exception\PermissionDeniedException;
use Neoncube\FlarumPrivateMessages\ConversationAccess;
use Neoncube\FlarumPrivateMessages\Message;

class HideMessageHandler
{
    /** @var ConversationAccess */
    protected $access;

    public function __construct(ConversationAccess $access)
    {
        $this->access = $access;
    }

    public function handle(HideMessage $command)
    {
        $actor = $command->actor;
        $messageId = $command->messageId;

        if ($actor->isGuest()) {
            throw new PermissionDeniedException;
        }

        $actor->assertCan('deleteMessage');

        $message = Message::findOrFail($messageId);

        if ((int) $actor->id !== (int) $message->user_id) {
            throw new PermissionDeniedException;
        }

        // Owner should belong to the conversation; verify explicitly.
        $this->access->assertParticipantInConversationId($actor, $message->conversation_id);

        $message->is_hidden = true;
        $message->save();
    }
}

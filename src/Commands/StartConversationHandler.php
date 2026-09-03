<?php

namespace Neoncube\FlarumPrivateMessages\Commands;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\ConnectionInterface;
use Neoncube\FlarumPrivateMessages\Conversation;
use Neoncube\FlarumPrivateMessages\ConversationAccess;
use Neoncube\FlarumPrivateMessages\ConversationUser;
use Neoncube\FlarumPrivateMessages\ConversationValidator;

class StartConversationHandler
{
    protected $bus;
    protected $access;
    protected $validator;
    protected $db;

    public function __construct(
        BusDispatcher $bus,
        ConversationAccess $access,
        ConversationValidator $validator,
        ConnectionInterface $db
    ) {
        $this->bus = $bus;
        $this->access = $access;
        $this->validator = $validator;
        $this->db = $db;
    }

    public function handle(StartConversation $command)
    {
        $actor = $command->actor;
        $data = $command->data;

        $actor->assertCan('startConversation');

        $recipientId = $this->validator->normalizeRecipientId(
            $data['attributes']['recipient'] ?? null,
            $actor
        );
        $messageContents = $this->validator->messageContentsFromData($data);

        $oldConversation = $this->validator->findExactOneToOneConversation($actor, $recipientId);

        if ($oldConversation) {
            $this->access->assertParticipant($actor, $oldConversation);
            $oldConversation->notNew = true;
            return $oldConversation;
        }

        return $this->db->transaction(function () use ($actor, $data, $recipientId, $messageContents) {
            $conversation = Conversation::start();
            $conversation->save();

            foreach ([$actor->id, $recipientId] as $participantId) {
                $recipient = new ConversationUser();
                $recipient->conversation_id = $conversation->id;
                $recipient->user_id = $participantId;
                $recipient->save();
            }

            // Reuse NewMessage for the initial body after participants exist.
            $payload = $data;
            $payload['attributes']['messageContents'] = $messageContents;
            $payload['attributes']['conversationId'] = $conversation->id;

            $this->bus->dispatch(
                new NewMessage($actor, $payload, $conversation->id)
            );

            return $conversation;
        });
    }
}

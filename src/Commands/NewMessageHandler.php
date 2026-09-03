<?php

namespace Neoncube\FlarumPrivateMessages\Commands;

use Flarum\Notification\NotificationSyncer;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Flarum\Settings\SettingsRepositoryInterface;
use Neoncube\FlarumPrivateMessages\Conversation;
use Neoncube\FlarumPrivateMessages\ConversationAccess;
use Neoncube\FlarumPrivateMessages\ConversationUser;
use Neoncube\FlarumPrivateMessages\ConversationValidator;
use Neoncube\FlarumPrivateMessages\Message;
use Neoncube\FlarumPrivateMessages\Notifications\NewPrivateMessageBlueprint;
use Pusher\Pusher;

class NewMessageHandler
{
    protected $notifications;
    protected $settings;
    protected $access;
    protected $validator;

    public function __construct(
        NotificationSyncer $notifications,
        SettingsRepositoryInterface $settings,
        ConversationAccess $access,
        ConversationValidator $validator
    ) {
        $this->notifications = $notifications;
        $this->settings = $settings;
        $this->access = $access;
        $this->validator = $validator;
    }

    public function handle(NewMessage $command)
    {
        $actor = $command->actor;
        $data = $command->data;
        $conversationId = $command->conversationId;

        if ($actor->isGuest()) {
            throw new PermissionDeniedException;
        }

        if (!$conversationId) {
            $conversationId = $data['attributes']['conversationId'] ?? null;
        }

        // Authorize before any mutation.
        $conversation = $this->access->assertParticipantInConversationId($actor, $conversationId);
        $messageContents = $this->validator->messageContentsFromData($data);

        $conversation->increment('total_messages');

        $message = Message::newMessage($messageContents, $actor->id, $conversation->id);

        $message->number = $conversation->total_messages;
        $message->save();

        foreach (ConversationUser::where('conversation_id', $conversation->id)->pluck('user_id')->all() as $userId) {
            if ((int) $userId === (int) $actor->id) {
                continue;
            }

            $recipient = User::find($userId);
            if (!$recipient) {
                continue;
            }

            $recipient->increment('unread_messages');

            $this->pushNewMessage($message, $conversation->id, $recipient);
            $this->sendNewMessageNotification($message, $conversation, $actor, $recipient);
        }

        return $message;
    }

    public function pushNewMessage($message, $conversationId, User $recipient)
    {
        if (app()->bound(Pusher::class)) {
            app(Pusher::class)->trigger('private-user' . $recipient->id, 'newMessage', [
                'id' => $message->id,
                'message' => $message->message,
                'createdAt' => (new \DateTime($message->created_at))->format(\DateTime::RFC3339),
                'conversationId' => $conversationId
            ]);
        }
    }

    public function sendNewMessageNotification($message, $conversation, $actor, $recipient)
    {
        if (!$recipient->can('neoncube-private-messages.allowUsersToReceiveEmailNotifications')) {
            return;
        }

        $this->notifications->sync(
            new NewPrivateMessageBlueprint($message, $conversation, $actor, $this->settings),
            [$recipient]
        );
    }
}

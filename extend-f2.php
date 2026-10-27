<?php

/*
 * Flarum 2.0.2 backend for the private-message bridge.
 *
 * Native Flarum messages store dialogs, not these conversation rows, so this
 * bridge remains the reader for the historical private-message tables.
 * Forum JS registers app.flatRateMessagingSources.direct only (no /messages
 * route ownership). Email notifications and the Pusher typing route stay
 * unregistered. Authorization still requires a participant row.
 */

use Flarum\Api\Serializer\CurrentUserSerializer;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Extend;
use Neoncube\FlarumPrivateMessages\F2\CreateConversationAction;
use Neoncube\FlarumPrivateMessages\F2\CreateMessageAction;
use Neoncube\FlarumPrivateMessages\F2\DeleteMessageAction;
use Neoncube\FlarumPrivateMessages\F2\ListConversationsAction;
use Neoncube\FlarumPrivateMessages\F2\ListMessagesAction;
use Neoncube\FlarumPrivateMessages\F2\ReadMessageAction;
use Neoncube\FlarumPrivateMessages\F2\ShowConversationAction;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum-f2.js')
        ->css(__DIR__.'/resources/less/extension.less')
        ->css(__DIR__.'/resources/less/messages-v2-bubbles.less'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\Routes('api'))
        ->get('/neoncube-private-messages/conversations', 'neoncube-private-messages.conversations.index', ListConversationsAction::class)
        ->get('/neoncube-private-messages/messages/{id}', 'neoncube-private-messages.messages.list', ListMessagesAction::class)
        ->post('/neoncube-private-messages/conversations', 'neoncube-private-messages.conversations.create', CreateConversationAction::class)
        ->post('/neoncube-private-messages/messages', 'neoncube-private-messages.messages.create', CreateMessageAction::class)
        ->post('/neoncube-private-messages/messages/read', 'neoncube-private-messages.message.read', ReadMessageAction::class)
        ->delete('/neoncube-private-messages/messages/{id}', 'neoncube-private-messages.messages.delete', DeleteMessageAction::class)
        ->get('/neoncube-private-messages/conversations/{id}', 'neoncube-private-messages.conversations.show', ShowConversationAction::class),

    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attribute('canMessage', function (ForumSerializer $serializer) {
            return $serializer->getActor()->can('startConversation');
        })
        ->attribute('neoncubePrivateMessagesAllowUsersToReceiveEmailNotifications', function (ForumSerializer $serializer) {
            return $serializer->getActor()->can('neoncube-private-messages.allowUsersToReceiveEmailNotifications');
        }),

    (new Extend\ApiSerializer(CurrentUserSerializer::class))
        ->attribute('unreadMessages', function (CurrentUserSerializer $serializer) {
            return $serializer->getActor()->unread_messages;
        }),

    (new Extend\Settings())
        ->serializeToForum('neoncubePrivateMessagesInitialInstalledVersion', 'neoncube-private-messages.initial_installed_version', function ($value) {
            return $value;
        })
        ->serializeToForum('neoncubePrivateMessagesReturnKey', 'neoncube-private-messages.return_key', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('neoncubePrivateMessagesEnableCustomColors', 'neoncube-private-messages.enable_custom_colors', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('neoncubePrivateMessagesRecipientBackgroundColor', 'neoncube-private-messages.recipient_background_color')
        ->serializeToForum('neoncubePrivateMessagesSenderBackgroundColor', 'neoncube-private-messages.sender_background_color')
        ->serializeToForum('neoncubePrivateMessagesRecipientTextColor', 'neoncube-private-messages.recipient_text_color')
        ->serializeToForum('neoncubePrivateMessagesSenderTextColor', 'neoncube-private-messages.sender_text_color'),
];

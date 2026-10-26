<?php

/*
 * Flarum 2.0.2 backend for the private-message bridge.
 *
 * Native Flarum messages store dialogs, not these conversation rows, so this
 * bridge remains the reader for the historical private-message tables.
 * Browser assets, email notifications, and the Pusher typing route stay
 * unregistered. Authorization still requires a participant row.
 */

use Flarum\Extend;
use Neoncube\FlarumPrivateMessages\F2\CreateConversationAction;
use Neoncube\FlarumPrivateMessages\F2\CreateMessageAction;
use Neoncube\FlarumPrivateMessages\F2\DeleteMessageAction;
use Neoncube\FlarumPrivateMessages\F2\ListConversationsAction;
use Neoncube\FlarumPrivateMessages\F2\ListMessagesAction;
use Neoncube\FlarumPrivateMessages\F2\ReadMessageAction;
use Neoncube\FlarumPrivateMessages\F2\ShowConversationAction;

return [
    (new Extend\Routes('api'))
        ->get('/neoncube-private-messages/conversations', 'neoncube-private-messages.conversations.index', ListConversationsAction::class)
        ->get('/neoncube-private-messages/messages/{id}', 'neoncube-private-messages.messages.list', ListMessagesAction::class)
        ->post('/neoncube-private-messages/conversations', 'neoncube-private-messages.conversations.create', CreateConversationAction::class)
        ->post('/neoncube-private-messages/messages', 'neoncube-private-messages.messages.create', CreateMessageAction::class)
        ->post('/neoncube-private-messages/messages/read', 'neoncube-private-messages.message.read', ReadMessageAction::class)
        ->delete('/neoncube-private-messages/messages/{id}', 'neoncube-private-messages.messages.delete', DeleteMessageAction::class)
        ->get('/neoncube-private-messages/conversations/{id}', 'neoncube-private-messages.conversations.show', ShowConversationAction::class),
];

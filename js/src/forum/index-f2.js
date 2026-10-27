/**
 * Flarum 2.0.2 forum entry: register Direct messaging provider only.
 *
 * Skips ConversationsPage /messages ownership (Messaging UI owns /messages),
 * notification grid, dropdown chrome, and Pusher typing wiring.
 */
import app from 'flarum/forum/app';
import User from 'flarum/common/models/User';
import Model from 'flarum/common/Model';
import Message from './models/Message';
import Conversation from './models/Conversation';
import ConversationUser from './models/ConversationUser';
import registerDirectMessagingProvider from './registerDirectMessagingProvider';
import { getNeoncubePrivateMessagesColors } from '../admin-forum-common';

app.initializers.add('neoncube-private-messages', () => {
  app.store.models.messages = Message;
  app.store.models.conversations = Conversation;
  app.store.models.conversation_users = ConversationUser;

  User.prototype.conversations = Model.hasMany('conversations');
  User.prototype.unreadMessages = Model.attribute('unreadMessages');

  registerDirectMessagingProvider();

  setTimeout(() => {
    const colors = getNeoncubePrivateMessagesColors(app);
    const cssStyle = document.documentElement.style;
    cssStyle.setProperty('--neoncube-private-messages-sender-background-color', colors.senderBackgroundColor);
    cssStyle.setProperty('--neoncube-private-messages-recipient-background-color', colors.recipientBackgroundColor);
    cssStyle.setProperty('--neoncube-private-messages-sender-text-color', colors.senderTextColor);
    cssStyle.setProperty('--neoncube-private-messages-recipient-text-color', colors.recipientTextColor);
  });
});

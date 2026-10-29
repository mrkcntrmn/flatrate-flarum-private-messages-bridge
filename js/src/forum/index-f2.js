/**
 * Flarum 2.0.2 forum entry: register Direct messaging provider and the
 * notification subject/store seams required for the bell.
 *
 * Skips ConversationsPage /messages ownership (Messaging UI owns /messages),
 * notification preference grid chrome, dropdown chrome, and Pusher typing wiring.
 *
 * EXEC-005 proved MessageResource alone is insufficient: subject includes use
 * type `neoncube-private-messages-messages`, and the bell needs
 * `notificationComponents.newPrivateMessage`.
 */
import app from 'flarum/forum/app';
import User from 'flarum/common/models/User';
import Model from 'flarum/common/Model';
import Message from './models/Message';
import Conversation from './models/Conversation';
import ConversationUser from './models/ConversationUser';
import NewPrivateMessageNotification from './components/NewPrivateMessageNotification';
import registerDirectMessagingProvider from './registerDirectMessagingProvider';
import { getNeoncubePrivateMessagesColors } from '../admin-forum-common';

app.initializers.add('neoncube-private-messages', () => {
  // Legacy short names used by provider conversation/message fetches.
  app.store.models.messages = Message;
  app.store.models.conversations = Conversation;
  app.store.models.conversation_users = ConversationUser;
  // F2 MessageResource type string for NotificationResource subject includes.
  app.store.models['neoncube-private-messages-messages'] = Message;

  User.prototype.conversations = Model.hasMany('conversations');
  User.prototype.unreadMessages = Model.attribute('unreadMessages');

  app.notificationComponents.newPrivateMessage = NewPrivateMessageNotification;

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

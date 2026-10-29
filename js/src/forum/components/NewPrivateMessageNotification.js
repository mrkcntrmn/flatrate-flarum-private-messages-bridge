import Notification from 'flarum/forum/components/Notification';
import resolveNewPrivateMessageHref from '../utils/resolveNewPrivateMessageHref';

export default class NewPrivateMessageNotification extends Notification {
  icon() {
    return 'fas fa-paper-plane';
  }

  href() {
    return resolveNewPrivateMessageHref(app, this.attrs.notification);
  }

  content() {
    const notification = this.attrs.notification;
    let fromUser = null;
    try {
      fromUser = notification && typeof notification.fromUser === 'function' ? notification.fromUser() : null;
    } catch (error) {
      fromUser = null;
    }

    const username =
      (fromUser && fromUser.data && fromUser.data.attributes && fromUser.data.attributes.displayName) ||
      app.translator.trans('core.lib.username.deleted_text');

    return app.translator.trans('neoncube-private-messages.forum.notifications.web.new_private_message.body', {
      fromUser: username,
    });
  }

  /**
   * Flarum 2 NotificationList always calls excerpt(). Missing/deleted subjects
   * must return null rather than throw or leak message body text.
   */
  excerpt() {
    return null;
  }
}

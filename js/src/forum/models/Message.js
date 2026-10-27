import Model from 'flarum/common/Model';
import mixin from 'flarum/common/utils/mixin';

export default class Message extends mixin(Model, {
  message: Model.attribute('message'),
  userId: Model.attribute('userId'),
  user: Model.hasOne('user'),
  isHidden: Model.attribute('isHidden'),
  createdAt: Model.attribute('createdAt', Model.transformDate),
  conversation: Model.hasOne('conversation'),
  conversationId: Model.attribute('conversationId'),
  number: Model.attribute('number'),
}) {
  apiEndpoint() {
    return `/neoncube-private-messages/messages${this.exists ? `/${this.data.id}` : ''}`;
  }
}

import Page from 'flarum/common/components/Page';

import ConversationsList from './ConversationsList';
import { messagingUiEnabled } from '../utils/messagingUiEnabled';

export default class ConversationsPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);

    this.currentConversationId = m.route.param('id');

    if (messagingUiEnabled()) {
      if (this.currentConversationId != null && this.currentConversationId !== '') {
        m.route.set(`/messages/direct/${this.currentConversationId}`, null, { replace: true });
      } else {
        m.route.set('/messages?filter=direct', null, { replace: true });
      }
      return;
    }

    this.bodyClass = 'App--conversations';
  }

  view(vnode) {
    if (messagingUiEnabled()) {
      return null;
    }

    return (
      <div className={this.currentConversationId != null ? 'ConversationsPage viewing-conversation' : 'ConversationsPage'}>
        <ConversationsList currentConversationId={this.currentConversationId} />
      </div>
    );
  }
}

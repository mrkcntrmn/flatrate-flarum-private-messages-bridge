import { extend } from 'flarum/common/extend';
import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import UserControls from 'flarum/forum/utils/UserControls';
import StartConversationModal from './components/StartConversationModal';
import canOfferDirectMessage from './utils/canOfferDirectMessage';

function openDirectMessageModal(user) {
  app.cache.conversations = app.cache.conversations || [];
  app.cache.messages = app.cache.messages || [];

  app.modal.show(StartConversationModal, {
    conversations: app.cache.conversations,
    messages: app.cache.messages,
    recipient: user,
  });
}

export default function addDirectMessageUserControl() {
  extend(UserControls, 'userControls', function (items, user) {
    if (
      !canOfferDirectMessage({
        actor: app.session.user,
        targetUser: user,
        canMessage: !!app.forum.attribute('canMessage'),
      })
    ) {
      return;
    }

    items.add(
      'directMessage',
      <Button icon="fas fa-comment-alt" onclick={() => openDirectMessageModal(user)}>
        {app.translator.trans('neoncube-private-messages.forum.profile.direct_message')}
      </Button>,
      90
    );
  });
}

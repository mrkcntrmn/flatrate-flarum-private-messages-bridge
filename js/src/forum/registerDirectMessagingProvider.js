import app from 'flarum/forum/app';
import ConversationView from './components/ConversationView';
import MessagesDirectConversationView from './components/MessagesDirectConversationView';
import StartConversationModal from './components/StartConversationModal';
import {
  findExactOneToOneConversation,
  idOf,
  normalizeDirectConversation,
} from './utils/normalizeDirectConversation';
import {
  createResolveConversation,
  isRenderableDirectConversation,
  rememberConversationInCache,
} from './utils/resolveDirectConversation';

function actorId() {
  const user = app.session?.user;
  if (!user || typeof user.id !== 'function') {
    return null;
  }
  return user.id();
}

async function loadConversations() {
  const results = await app.store.find('neoncube-private-messages/conversations');
  if (results && results.payload) {
    delete results.payload;
  }
  app.cache = app.cache || {};
  app.cache.conversations = Array.isArray(results) ? results : [];
  return app.cache.conversations;
}

function conversationByKey(key) {
  const id = String(key);
  const cached = (app.cache?.conversations || []).find((conversation) => String(idOf(conversation)) === id);
  if (cached) {
    return cached;
  }
  return app.store?.getById?.('conversations', id) || null;
}

function rememberConversation(conversation) {
  app.cache = app.cache || {};
  rememberConversationInCache(app.cache, conversation);
}

async function fetchConversationById(id) {
  const result = await app.store.find('neoncube-private-messages/conversations', id, {}, {
    // Swallow Flarum's default 403/404 alerts so Direct routes are not an existence oracle.
    errorHandler() {},
  });
  if (result && result.payload) {
    delete result.payload;
  }
  if (Array.isArray(result)) {
    return result[0] || null;
  }
  return result || null;
}

const resolveInFlight = new Map();
const resolveConversation = createResolveConversation({
  getCached: conversationByKey,
  fetchById: fetchConversationById,
  remember: rememberConversation,
  inflight: resolveInFlight,
});

/**
 * Registers app.flatRateMessagingSources.direct.
 *
 * findConversationWithUser returns the conversation model (or null) so the shell
 * can route with conversation.id() / { id: 'direct:'+id, key: String(id) }.
 *
 * resolveConversation returns the authorized conversation for a canonical Direct
 * route without exposing Neoncube URLs on app.flatrateMessaging.
 */
export default function registerDirectMessagingProvider() {
  app.flatRateMessagingSources ??= {};
  app.flatRateMessagingSources.direct = {
    schemaVersion: 1,
    kind: 'direct',
    async listConversations() {
      const conversations = await loadConversations();
      const id = actorId();
      return conversations.map((conversation) => normalizeDirectConversation(conversation, id));
    },
    getUnreadTotal() {
      const user = app.session?.user;
      if (!user || typeof user.unreadMessages !== 'function') {
        return 0;
      }
      return user.unreadMessages() || 0;
    },
    getLoadedConversation(conversationId) {
      const conversation = conversationByKey(conversationId);
      return isRenderableDirectConversation(conversation) ? conversation : null;
    },
    resolveConversation,
    renderConversation({ key, context }) {
      const conversation = conversationByKey(key);
      const initialDraft = context?.draft || context?.initialDraft || '';
      if (context?.presentationVersion === 2) {
        return (
          <MessagesDirectConversationView
            key={key}
            conversation={conversation}
            initialDraft={initialDraft}
          />
        );
      }
      return <ConversationView conversation={conversation} initialDraft={initialDraft} />;
    },
    async findConversationWithUser(user) {
      const targetId = typeof user?.id === 'function' ? user.id() : user?.id;
      app.cache = app.cache || {};
      if (!app.cache.conversations || app.cache.conversations.length === 0) {
        await loadConversations();
      }
      return findExactOneToOneConversation(app.cache.conversations || [], actorId(), targetId);
    },
    startConversationWithUser(user, options) {
      app.cache = app.cache || {};
      app.cache.conversations = app.cache.conversations || [];
      app.modal.show(StartConversationModal, {
        conversations: app.cache.conversations,
        messages: app.cache.messages,
        recipient: user,
        onConversationResolved: options?.onConversationResolved,
      });
    },
    async refresh() {
      return this.listConversations();
    },
  };
}

import { idOf } from './normalizeDirectConversation.js';

/**
 * A Direct conversation is renderable only when recipients are present.
 * Cold canonical routes must not pass a null/partial model into ConversationView.
 */
export function isRenderableDirectConversation(conversation) {
  if (!conversation || typeof conversation.recipients !== 'function') {
    return false;
  }

  try {
    const recipients = conversation.recipients();
    return Array.isArray(recipients) && recipients.length > 0;
  } catch (error) {
    return false;
  }
}

export function httpStatusOf(error) {
  if (!error || typeof error !== 'object') {
    return null;
  }
  if (typeof error.status === 'number') {
    return error.status;
  }
  if (typeof error.statusCode === 'number') {
    return error.statusCode;
  }
  const response = error.response;
  if (response && typeof response.status === 'number') {
    return response.status;
  }
  return null;
}

/**
 * 401/403/404 are fail-closed. The UI must treat them as the same unavailable state
 * so a Direct route cannot become an existence oracle.
 */
export function isFailClosedResolveError(error) {
  const status = httpStatusOf(error);
  return status === 401 || status === 403 || status === 404;
}

export function rememberConversationInCache(cache, conversation) {
  if (!cache || !conversation) {
    return conversation;
  }

  const conversations = Array.isArray(cache.conversations) ? cache.conversations.slice() : [];
  const id = String(idOf(conversation));
  const index = conversations.findIndex((item) => String(idOf(item)) === id);

  if (index === -1) {
    conversations.push(conversation);
  } else {
    conversations[index] = conversation;
  }

  cache.conversations = conversations;
  return conversation;
}

/**
 * Resolve an authorized Direct conversation by canonical id.
 *
 * @returns {Promise<object|null>}
 */
export function createResolveConversation({ getCached, fetchById, remember, inflight }) {
  const pending = inflight || new Map();

  return async function resolveConversation(conversationId) {
    const id = String(conversationId ?? '');
    if (!id) {
      return null;
    }

    const cached = getCached(id);
    if (isRenderableDirectConversation(cached)) {
      return cached;
    }

    if (pending.has(id)) {
      return pending.get(id);
    }

    const promise = (async () => {
      try {
        const conversation = await fetchById(id);
        if (conversation) {
          remember(conversation);
        }
        return conversation || null;
      } catch (error) {
        if (isFailClosedResolveError(error)) {
          return null;
        }
        throw error;
      } finally {
        pending.delete(id);
      }
    })();

    pending.set(id, promise);
    return promise;
  };
}

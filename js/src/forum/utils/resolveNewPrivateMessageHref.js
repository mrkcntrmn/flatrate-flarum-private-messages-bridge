/**
 * Resolve a safe forum href for a newPrivateMessage notification.
 *
 * Flarum 1 registers legacy `messages` (/conversations/:id).
 * Flarum 2 Messaging UI owns /messages and registers
 * `flatrate-messaging.direct` (/messages/direct/:conversationId).
 * Prefer the Messaging UI route when present; never invent a competing
 * `messages` alias on the F2 stack.
 */
export default function resolveNewPrivateMessageHref(appInstance, notification) {
  const fallback =
    (appInstance.routes && appInstance.routes.index && appInstance.route('index')) || '/';

  if (!notification || typeof notification.subject !== 'function') {
    return fallback;
  }

  let message;
  try {
    message = notification.subject();
  } catch (error) {
    return fallback;
  }

  if (!message || !message.data || !message.data.attributes) {
    return fallback;
  }

  const conversationId = message.data.attributes.conversationId;
  if (conversationId === undefined || conversationId === null || conversationId === '') {
    return fallback;
  }

  if (appInstance.routes && appInstance.routes['flatrate-messaging.direct']) {
    return appInstance.route('flatrate-messaging.direct', { conversationId });
  }

  if (appInstance.routes && appInstance.routes.messages) {
    return appInstance.route('messages', { id: conversationId });
  }

  return fallback;
}

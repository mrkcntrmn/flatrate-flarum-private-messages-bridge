/**
 * Normalize Direct (1:1) conversations for the unified messaging shell.
 * Rows never include message bodies.
 */

export function idOf(model) {
  if (model == null) {
    return null;
  }
  if (typeof model.id === 'function') {
    return model.id();
  }
  if (model.id != null) {
    return model.id;
  }
  if (model.data && model.data.id != null) {
    return model.data.id;
  }
  return null;
}

function readAttr(model, key) {
  if (!model) {
    return null;
  }
  if (typeof model[key] === 'function') {
    try {
      return model[key]();
    } catch (e) {
      return null;
    }
  }
  return model[key] ?? null;
}

export function otherParticipants(conversation, actorId) {
  const recipients =
    typeof conversation?.recipients === 'function' ? conversation.recipients() : conversation?.recipients;
  if (!recipients || typeof recipients.filter !== 'function') {
    return [];
  }

  const actor = String(actorId);
  return recipients
    .map((recipient) => (typeof recipient?.user === 'function' ? recipient.user() : recipient?.user))
    .filter((user) => user && String(idOf(user)) !== actor);
}

/** Other recipient !== actor, matching UserListItemContent. */
export function otherParticipant(conversation, actorId) {
  return otherParticipants(conversation, actorId)[0] || null;
}

export function participantTitle(user) {
  if (!user) {
    return '';
  }
  return readAttr(user, 'displayName') || readAttr(user, 'username') || readAttr(user, 'nickname') || '';
}

/** Public Flarum User model accessor only. */
export function participantAvatarUrl(user) {
  if (!user) {
    return null;
  }
  const url = readAttr(user, 'avatarUrl');
  return typeof url === 'string' && url.length ? url : null;
}

export function activityAtIso(conversation) {
  if (!conversation || typeof conversation.updatedAt !== 'function') {
    return null;
  }
  const value = conversation.updatedAt();
  if (!value) {
    return null;
  }
  if (value instanceof Date) {
    return value.toISOString();
  }
  if (typeof value.toISOString === 'function') {
    return value.toISOString();
  }
  if (typeof value === 'string') {
    return value;
  }
  return null;
}

/**
 * Exact 1:1: a single other recipient whose id matches userId.
 *
 * @returns {object|null} conversation model
 */
export function findExactOneToOneConversation(conversations, actorId, userId) {
  if (!conversations || !conversations.length) {
    return null;
  }
  const target = String(userId);
  for (const conversation of conversations) {
    const others = otherParticipants(conversation, actorId);
    if (others.length === 1 && String(idOf(others[0])) === target) {
      return conversation;
    }
  }
  return null;
}

export function normalizeDirectConversation(conversation, actorId) {
  const conversationId = idOf(conversation);
  const other = otherParticipant(conversation, actorId);
  const unread = typeof conversation?.unReadCount === 'function' ? conversation.unReadCount() : 0;

  return {
    id: 'direct:' + conversationId,
    kind: 'direct',
    key: String(conversationId),
    title: participantTitle(other),
    avatarUrl: participantAvatarUrl(other),
    activityAt: activityAtIso(conversation),
    unreadCount: unread || 0,
    isPublic: false,
    userId: other ? String(idOf(other)) : null,
    conversationId: String(conversationId),
  };
}

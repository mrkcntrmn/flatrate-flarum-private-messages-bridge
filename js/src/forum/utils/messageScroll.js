/**
 * Direct V2 scroll helpers (presentation-only; no Direct DOM class coupling).
 * Mirrors messaging-ui messageScroll contract for provider-local use.
 */

export const NEAR_BOTTOM_PX = 100;

export function isNearBottom(viewport, thresholdPx = NEAR_BOTTOM_PX) {
  if (!viewport) {
    return false;
  }
  const scrollTop = Number(viewport.scrollTop) || 0;
  const clientHeight = Number(viewport.clientHeight) || 0;
  const scrollHeight = Number(viewport.scrollHeight) || 0;
  return scrollHeight - (scrollTop + clientHeight) <= thresholdPx;
}

export function calculatePreservedScrollTop(oldHeight, oldTop, newHeight) {
  return (Number(oldTop) || 0) + ((Number(newHeight) || 0) - (Number(oldHeight) || 0));
}

export function shouldAutoScrollIncoming({ nearBottom = false, isOwnSend = false, hasInitialPositioned = true } = {}) {
  if (!hasInitialPositioned) return false;
  if (isOwnSend) return true;
  return !!nearBottom;
}

export function shouldInitialScroll({
  hasInitialPositioned = false,
  conversationKey = null,
  currentConversationKey = null,
  hasRenderedMessages = false,
} = {}) {
  if (!hasRenderedMessages) return false;
  if (conversationKey == null || currentConversationKey == null) return false;
  if (String(conversationKey) !== String(currentConversationKey)) return true;
  return !hasInitialPositioned;
}

export function scrollToLatest(viewport) {
  if (!viewport) return;
  viewport.scrollTop = Number(viewport.scrollHeight) || 0;
}

/** Group adjacent messages from the same sender within 5 minutes (presentation-only). */
export function groupAdjacentMessages(messages, { gapMs = 5 * 60 * 1000, isOwn } = {}) {
  const groups = [];
  for (const message of messages) {
    const own = typeof isOwn === 'function' ? !!isOwn(message) : false;
    const createdAt = message?.createdAt?.() || message?.createdAt || null;
    const ts = createdAt ? new Date(createdAt).getTime() : 0;
    const userId = message?.user?.()?.id?.() ?? message?.user?.()?.id ?? null;
    const last = groups[groups.length - 1];
    if (
      last &&
      last.own === own &&
      String(last.userId) === String(userId) &&
      ts &&
      last.lastTs &&
      ts - last.lastTs <= gapMs
    ) {
      last.messages.push(message);
      last.lastTs = ts;
    } else {
      groups.push({ own, userId, messages: [message], lastTs: ts });
    }
  }
  return groups;
}

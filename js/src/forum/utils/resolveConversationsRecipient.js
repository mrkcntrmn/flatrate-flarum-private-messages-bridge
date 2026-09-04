/**
 * Resolve optional StartConversationModal recipient attr to a Flarum User model.
 * Rejects non-model values (strings, raw IDs, plain objects).
 *
 * @param {*} recipient
 * @returns {*|null}
 */
export default function resolveConversationsRecipient(recipient) {
  if (recipient == null) {
    return null;
  }

  if (typeof recipient.id !== 'function') {
    return null;
  }

  return recipient;
}

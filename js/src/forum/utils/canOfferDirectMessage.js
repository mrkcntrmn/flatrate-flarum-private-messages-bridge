/**
 * Presentation-only gate for the profile Direct Message control.
 * Server authorization remains authoritative for conversation create.
 *
 * @param {{ actor: { id: () => string|number }|null|undefined, targetUser: { id: () => string|number }|null|undefined, canMessage: boolean }} args
 * @returns {boolean}
 */
export default function canOfferDirectMessage({ actor, targetUser, canMessage }) {
  if (!actor || !targetUser) {
    return false;
  }

  if (!canMessage) {
    return false;
  }

  if (typeof targetUser.id !== 'function' || typeof actor.id !== 'function') {
    return false;
  }

  return targetUser.id() !== actor.id();
}

#!/usr/bin/env node
/**
 * Focused source/unit checks for FORUM-DM-001G Direct Message entrypoints.
 * Does not replace disposable runtime security/product matrices.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '../..');
const JS_SRC = join(ROOT, 'js/src/forum');

const canOffer = (
  await import(pathToFileURL(join(JS_SRC, 'utils/canOfferDirectMessage.js')).href)
).default;
const resolveRecipient = (
  await import(pathToFileURL(join(JS_SRC, 'utils/resolveConversationsRecipient.js')).href)
).default;

function user(id) {
  return { id: () => String(id) };
}

function test(name, fn) {
  try {
    fn();
    console.error(`[PASS] ${name}`);
  } catch (err) {
    console.error(`[FAIL] ${name}`);
    throw err;
  }
}

test('SIDEBAR_DIRECT_MESSAGE_LABEL', () => {
  const en = readFileSync(join(ROOT, 'resources/locale/en.yml'), 'utf8');
  assert.match(en, /tooltip:\s*Direct Message\b/);
  assert.doesNotMatch(en, /tooltip:\s*Conversations\b/);
});

test('PROFILE_DM_BUTTON_VISIBLE', () => {
  assert.equal(
    canOffer({ actor: user(1), targetUser: user(2), canMessage: true }),
    true
  );
});

test('PROFILE_DM_SELF_HIDDEN', () => {
  assert.equal(
    canOffer({ actor: user(1), targetUser: user(1), canMessage: true }),
    false
  );
});

test('PROFILE_DM_GUEST_HIDDEN', () => {
  assert.equal(
    canOffer({ actor: null, targetUser: user(2), canMessage: true }),
    false
  );
});

test('PROFILE_DM_PERMISSION_GATE', () => {
  assert.equal(
    canOffer({ actor: user(1), targetUser: user(2), canMessage: false }),
    false
  );
});

test('PROFILE_DM_RECIPIENT_PREFILL', () => {
  const b = user(3);
  assert.equal(resolveRecipient(b), b);
});

test('EXISTING_START_CONVERSATION_FLOW', () => {
  assert.equal(resolveRecipient(null), null);
  assert.equal(resolveRecipient(undefined), null);
});

test('reject non-model recipient attrs', () => {
  assert.equal(resolveRecipient('WrenchBeta'), null);
  assert.equal(resolveRecipient(3), null);
  assert.equal(resolveRecipient({ id: 3 }), null);
  assert.equal(resolveRecipient({ username: 'bob' }), null);
});

test('profile control uses UserControls.userControls', () => {
  const src = readFileSync(join(JS_SRC, 'addDirectMessageUserControl.js'), 'utf8');
  assert.match(src, /UserControls/);
  assert.match(src, /userControls/);
  assert.match(src, /fas fa-comment-alt/);
  assert.match(src, /recipient:\s*user/);
  assert.match(src, /StartConversationModal/);
  assert.doesNotMatch(src, /MutationObserver|setInterval|jQuery|append\(/);
});

test('modal uses resolveConversationsRecipient', () => {
  const src = readFileSync(join(JS_SRC, 'components/StartConversationModal.js'), 'utf8');
  assert.match(src, /resolveConversationsRecipient/);
  assert.match(src, /recipient\.id\(\)/);
});

test('ConversationsList showModal does not force recipient', () => {
  const src = readFileSync(join(JS_SRC, 'components/ConversationsList.js'), 'utf8');
  assert.match(
    src,
    /app\.modal\.show\(StartConversationModal,\s*\{\s*conversations:[\s\S]*messages:[\s\S]*\}\)/
  );
  assert.doesNotMatch(src, /recipient:\s*/);
});

test('index wires profile control', () => {
  const src = readFileSync(join(JS_SRC, 'index.js'), 'utf8');
  assert.match(src, /addDirectMessageUserControl/);
});

console.error('DM001G_FRONTEND_UNIT=PASS');

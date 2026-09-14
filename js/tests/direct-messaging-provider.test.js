#!/usr/bin/env node
/**
 * Focused source/unit checks for FORUM-MESSAGING-001 Direct provider + unread/nav.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '../..');
const JS_SRC = join(ROOT, 'js/src/forum');

const {
  findExactOneToOneConversation,
  normalizeDirectConversation,
} = await import(pathToFileURL(join(JS_SRC, 'utils/normalizeDirectConversation.js')).href);

function test(name, fn) {
  try {
    fn();
    console.error(`[PASS] ${name}`);
  } catch (err) {
    console.error(`[FAIL] ${name}`);
    throw err;
  }
}

function person(id, { displayName, username, nickname } = {}) {
  return {
    id: () => String(id),
    displayName: () => displayName,
    username: () => username,
    nickname: () => nickname,
  };
}

function recipient(user) {
  return { user: () => user };
}

function conversationFixture({ id, updatedAt, unReadCount, people }) {
  return {
    id: () => id,
    updatedAt: () => updatedAt,
    unReadCount: () => unReadCount,
    recipients: () => people.map(recipient),
    message: () => 'secret body must never leak',
  };
}

test('Conversation.js includes unReadCount', () => {
  const src = readFileSync(join(JS_SRC, 'models/Conversation.js'), 'utf8');
  assert.match(src, /unReadCount:\s*Model\.attribute\('unReadCount'\)/);
});

test('normalizeDirectConversation maps shell row without message body', () => {
  const at = new Date('2026-09-14T15:00:00.000Z');
  const conversation = conversationFixture({
    id: 9,
    updatedAt: at,
    unReadCount: 2,
    people: [person(1, { username: 'alice' }), person(2, { displayName: 'Bob', username: 'bob' })],
  });
  const row = normalizeDirectConversation(conversation, 1);
  assert.deepEqual(row, {
    id: 'direct:9',
    kind: 'direct',
    key: '9',
    title: 'Bob',
    activityAt: '2026-09-14T15:00:00.000Z',
    unreadCount: 2,
    isPublic: false,
    userId: '2',
    conversationId: '9',
  });
  assert.equal(Object.prototype.hasOwnProperty.call(row, 'message'), false);
  assert.equal(JSON.stringify(row).includes('secret body'), false);
});

test('findExactOneToOneConversation matches other recipient', () => {
  const ab = conversationFixture({
    id: 11,
    updatedAt: null,
    unReadCount: 0,
    people: [person(1), person(2)],
  });
  const ac = conversationFixture({
    id: 12,
    updatedAt: null,
    unReadCount: 0,
    people: [person(1), person(3)],
  });
  const group = conversationFixture({
    id: 13,
    updatedAt: null,
    unReadCount: 0,
    people: [person(1), person(2), person(3)],
  });
  assert.equal(findExactOneToOneConversation([ab, ac, group], 1, 2), ab);
  assert.equal(findExactOneToOneConversation([ab, ac, group], 1, 3), ac);
  assert.equal(findExactOneToOneConversation([group], 1, 2), null);
});

test('provider registration source contract', () => {
  const index = readFileSync(join(JS_SRC, 'index.js'), 'utf8');
  const provider = readFileSync(join(JS_SRC, 'registerDirectMessagingProvider.js'), 'utf8');
  assert.match(index, /registerDirectMessagingProvider/);
  assert.match(provider, /schemaVersion:\s*1/);
  assert.match(provider, /kind:\s*'direct'/);
  assert.match(provider, /listConversations/);
  assert.match(provider, /getUnreadTotal/);
  assert.match(provider, /renderConversation/);
  assert.match(provider, /findConversationWithUser/);
  assert.match(provider, /startConversationWithUser/);
  assert.match(provider, /onConversationResolved/);
  assert.match(provider, /initialDraft/);
  assert.match(provider, /unreadMessages\(\)/);
  assert.doesNotMatch(provider, /messageContents|messageBody|lastMessage/);
});

test('StartConversationModal unified callback; clipboard only in callback-absent branch', () => {
  const src = readFileSync(join(JS_SRC, 'components/StartConversationModal.js'), 'utf8');
  assert.match(src, /onConversationResolved/);
  assert.match(src, /const onResolved = this\.attrs\.onConversationResolved/);
  assert.match(src, /created:\s*true,\s*draft:\s*null/);
  assert.match(src, /created:\s*false,\s*draft/);

  const onsubmit = src.slice(src.indexOf('onsubmit(e)'));
  const ifResolved = onsubmit.indexOf('if (onResolved)');
  assert.ok(ifResolved >= 0);
  const afterIf = onsubmit.slice(ifResolved);
  const returnPos = afterIf.indexOf('return;');
  assert.ok(returnPos >= 0);
  const callbackBranch = afterIf.slice(0, returnPos);
  assert.equal(callbackBranch.includes("execCommand('Copy')"), false);
  assert.equal(callbackBranch.includes('this.already = true'), false);
  assert.ok(afterIf.slice(returnPos).includes("execCommand('Copy')"));
});

test('ConversationView initialDraft is not auto-sent', () => {
  const src = readFileSync(join(JS_SRC, 'components/ConversationView.js'), 'utf8');
  assert.match(src, /this\.messageContent = Stream\(vnode\.attrs\.initialDraft \|\| ''\)/);
  const oninit = src.slice(src.indexOf('oninit(vnode)'), src.indexOf('onremove()'));
  assert.doesNotMatch(oninit, /sendMessage\(/);
});

test('messagingUiEnabled suppresses Messages dropdown and directMessage control', () => {
  const util = readFileSync(join(JS_SRC, 'utils/messagingUiEnabled.js'), 'utf8');
  assert.match(util, /export function messagingUiEnabled\(\)/);
  assert.match(util, /flatrateMessagingUiEnabled/);

  const dropdown = readFileSync(join(JS_SRC, 'addConversationsDropdown.js'), 'utf8');
  assert.match(dropdown, /messagingUiEnabled/);
  const dropInit = dropdown.slice(dropdown.indexOf("extend(HeaderSecondary.prototype, 'items'"));
  const dropReturn = dropInit.indexOf('if (messagingUiEnabled())');
  const dropAdd = dropInit.indexOf("items.add('Messages'");
  assert.ok(dropReturn >= 0 && dropAdd > dropReturn);

  const control = readFileSync(join(JS_SRC, 'addDirectMessageUserControl.js'), 'utf8');
  assert.match(control, /messagingUiEnabled/);
  const ctrlInit = control.slice(control.indexOf("extend(UserControls, 'userControls'"));
  const ctrlReturn = ctrlInit.indexOf('if (messagingUiEnabled())');
  const ctrlAdd = ctrlInit.indexOf("items.add(");
  assert.ok(ctrlReturn >= 0 && ctrlAdd > ctrlReturn);

  const page = readFileSync(join(JS_SRC, 'components/ConversationsPage.js'), 'utf8');
  assert.match(page, /\/messages\?filter=direct/);
  assert.match(page, /\/messages\/direct\/\$\{this\.currentConversationId\}/);
  assert.match(page, /replace:\s*true/);
});

test('private-message notification icons use paper-plane', () => {
  const notification = readFileSync(join(JS_SRC, 'components/NewPrivateMessageNotification.js'), 'utf8');
  assert.match(notification, /fas fa-paper-plane/);
  assert.doesNotMatch(notification, /fas fa-message/);

  const index = readFileSync(join(JS_SRC, 'index.js'), 'utf8');
  const grid = index.slice(index.indexOf("extend(NotificationGrid.prototype, 'notificationTypes'"));
  assert.match(grid, /fas fa-paper-plane/);
  assert.doesNotMatch(grid, /fas fa-comment-alt/);
});

console.error('MESSAGING001_DIRECT_PROVIDER=PASS');

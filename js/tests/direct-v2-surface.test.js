#!/usr/bin/env node
/**
 * FORUM-MESSAGING-002B Direct V2 presentation + scroll helper checks.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '../..');
const JS_SRC = join(ROOT, 'js/src/forum');

const {
  NEAR_BOTTOM_PX,
  isNearBottom,
  calculatePreservedScrollTop,
  shouldAutoScrollIncoming,
  shouldInitialScroll,
  groupAdjacentMessages,
} = await import(pathToFileURL(join(JS_SRC, 'utils/messageScroll.js')).href);

const pending = [];
function test(name, fn) {
  pending.push(
    (async () => {
      await fn();
      console.error(`[PASS] ${name}`);
    })()
  );
}

test('V2 presentation is gated; V1 ConversationView retained as default', () => {
  const provider = readFileSync(join(JS_SRC, 'registerDirectMessagingProvider.js'), 'utf8');
  assert.match(provider, /MessagesDirectConversationView/);
  assert.match(provider, /presentationVersion\s*===\s*2/);
  assert.match(provider, /<ConversationView/);
  assert.match(provider, /ConversationView conversation=\{conversation\}/);

  const v2 = readFileSync(join(JS_SRC, 'components/MessagesDirectConversationView.js'), 'utf8');
  assert.match(v2, /MessagesDirectSurface/);
  assert.match(v2, /MessagesMessageViewport/);
  assert.match(v2, /MessagesComposer/);
  assert.match(v2, /scrollToLatest/);
  assert.match(v2, /calculatePreservedScrollTop/);
  assert.match(v2, /shouldAutoScrollIncoming/);
  assert.doesNotMatch(v2, /chat-with|startConvo|Chat with/);
  assert.doesNotMatch(v2, /\$\(['"]\.chat-history['"]\)\.animate/);

  const v1 = readFileSync(join(JS_SRC, 'components/ConversationView.js'), 'utf8');
  assert.match(v1, /className="chat"/);
  assert.match(v1, /chat-history/);
});

test('Direct V2 uses existing endpoints only', () => {
  const v2 = readFileSync(join(JS_SRC, 'components/MessagesDirectConversationView.js'), 'utf8');
  assert.match(v2, /neoncube-private-messages\/messages/);
  assert.match(v2, /neoncube-private-messages\/messages\/read/);
  assert.match(v2, /neoncube-private-messages\/messages\/typing/);
  assert.match(v2, /messageContents/);
  assert.doesNotMatch(v2, /\/api\/messages-v2|shared-messages|supabase/i);
});

test('scroll helpers: near-bottom, pagination anchor, incoming policy', () => {
  assert.equal(NEAR_BOTTOM_PX, 100);
  assert.equal(isNearBottom({ scrollTop: 900, clientHeight: 100, scrollHeight: 1100 }), true);
  assert.equal(isNearBottom({ scrollTop: 899, clientHeight: 100, scrollHeight: 1100 }), false);
  assert.equal(calculatePreservedScrollTop(1000, 100, 1400), 500);
  assert.equal(shouldAutoScrollIncoming({ nearBottom: false, isOwnSend: true, hasInitialPositioned: true }), true);
  assert.equal(shouldAutoScrollIncoming({ nearBottom: false, isOwnSend: false, hasInitialPositioned: true }), false);
  assert.equal(
    shouldInitialScroll({
      hasInitialPositioned: true,
      conversationKey: '1',
      currentConversationKey: '2',
      hasRenderedMessages: true,
    }),
    true
  );
});

test('groupAdjacentMessages groups same sender within 5 minutes', () => {
  const mk = (id, userId, minutes) => ({
    id: () => String(id),
    user: () => ({ id: () => String(userId) }),
    createdAt: () => new Date(Date.UTC(2026, 8, 14, 12, minutes, 0)),
  });
  const groups = groupAdjacentMessages([mk(1, 2, 0), mk(2, 2, 2), mk(3, 2, 10), mk(4, 1, 11)], {
    isOwn: (m) => m.user().id() === '1',
  });
  assert.equal(groups.length, 3);
  assert.equal(groups[0].messages.length, 2);
  assert.equal(groups[1].messages.length, 1);
  assert.equal(groups[2].own, true);
});

test('send failure preserves draft path in source', () => {
  const v2 = readFileSync(join(JS_SRC, 'components/MessagesDirectConversationView.js'), 'utf8');
  assert.match(v2, /this\.messageContent\(draft\)/);
  assert.match(v2, /sendFailed\s*=\s*true/);
  assert.match(v2, /this\.isSending\s*=\s*true/);
});

test('Direct V2 own groups show avatar and nickname via canonical author', () => {
  const v2 = readFileSync(join(JS_SRC, 'components/MessagesDirectConversationView.js'), 'utf8');
  assert.match(v2, /authorForMessage\s*\(message\)\s*\{/);
  assert.match(v2, /app\.session\.user/);
  assert.match(v2, /MessagesBubble-avatar/);
  assert.match(v2, /MessagesBubble-name/);
  assert.match(v2, /avatar\(author\)/);
  assert.match(v2, /username\(author\)/);
  // Identity must not be gated behind !own.
  assert.doesNotMatch(v2, /!own\s*\?\s*<span className="MessagesBubble-avatar"/);
  assert.doesNotMatch(v2, /!own\s*\?\s*<span className="MessagesBubble-name"/);
  assert.match(v2, /MessagesMessageGroup--out/);
  assert.match(v2, /MessagesMessageGroup--in/);
});

test('V2 composer send is icon-only paper plane with aria-label', () => {
  const v2 = readFileSync(join(JS_SRC, 'components/MessagesDirectConversationView.js'), 'utf8');
  assert.match(v2, /icon="fas fa-paper-plane"/);
  assert.match(v2, /Button--icon/);
  assert.match(v2, /MessagesComposer-send/);
  assert.match(v2, /aria-label=\{this\.sendFailed \? 'Retry' : 'Send message'\}/);
  assert.doesNotMatch(v2, /MessagesComposer-send[\s\S]*?>[\s\S]*?Send Message/);
  assert.doesNotMatch(v2, /MessagesComposer-send[\s\S]*?>[\s\S]*?translator\.trans\('neoncube-private-messages\.forum\.chat\.send'\)/);
});

test('Direct V2 LESS keeps primary icon-send Button-icon visible', () => {
  const less = readFileSync(join(ROOT, 'resources/less/extension.less'), 'utf8');
  assert.match(
    less,
    /\.MessagesDirectSurface \.DirectComposer \.MessagesComposer-send\.Button--icon \.Button-icon/
  );
  assert.match(
    less,
    /\.MessagesDirectSurface \.MessagesComposer \.MessagesComposer-send\.Button--icon \.Button-icon/
  );
  assert.match(less, /display:\s*inline-block/);
});

await Promise.all(pending);
console.error('MESSAGING002_DIRECT_V2=PASS');

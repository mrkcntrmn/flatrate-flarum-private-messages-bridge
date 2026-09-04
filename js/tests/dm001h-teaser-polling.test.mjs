#!/usr/bin/env node
/**
 * FORUM-DM-001H source/unit gates: teaser DM email + ConversationView polling contracts.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '../..');

function test(name, fn) {
  try {
    fn();
    console.error(`[PASS] ${name}`);
  } catch (err) {
    console.error(`[FAIL] ${name}`);
    throw err;
  }
}

test('DM_EMAIL_MESSAGE_CONTENT_INCLUDED=false', () => {
  const view = readFileSync(join(ROOT, 'views/emails/newPrivateMessageHtml.blade.php'), 'utf8');
  assert.doesNotMatch(view, /message->message/);
  assert.doesNotMatch(view, /\{content\}/);
  assert.match(view, /viewMessage/);
  assert.match(view, /youHaveReceivedNewMessage/);
});

test('DM email copy is teaser-only', () => {
  const en = readFileSync(join(ROOT, 'resources/locale/en.yml'), 'utf8');
  assert.match(en, /sent you a direct message on FlatRate\.wiki/);
  assert.match(en, /viewMessage:\s*View Direct Message/);
  assert.doesNotMatch(en, /\{content\}/);
});

test('ConversationView polling contracts', () => {
  const src = readFileSync(join(ROOT, 'js/src/forum/components/ConversationView.js'), 'utf8');
  assert.match(src, /POLL_INTERVAL_MS\s*=\s*5000/);
  assert.match(src, /pollNewestMessages/);
  assert.match(src, /pollInFlight/);
  assert.match(src, /visibilitychange/);
  assert.match(src, /offset:\s*0/);
  assert.match(src, /document\.visibilityState/);
  assert.match(src, /clearInterval/);
  assert.match(src, /removeEventListener\('visibilitychange'/);
  assert.match(src, /NEAR_BOTTOM_PX/);
  assert.match(src, /messages\/read/);
  // history pagination still uses known count offset
  assert.match(src, /getMessages\(app\.cache\.messages\[this\.conversation\.id\(\)\]\.length\)/);
});

console.error('DM001H_FRONTEND_UNIT=PASS');

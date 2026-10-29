#!/usr/bin/env node
/**
 * F2 forum entry must register app.flatRateMessagingSources.direct without
 * claiming /messages route ownership.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const indexF2 = readFileSync(join(root, 'js/src/forum/index-f2.js'), 'utf8');
const provider = readFileSync(join(root, 'js/src/forum/registerDirectMessagingProvider.js'), 'utf8');
const extendF2 = readFileSync(join(root, 'extend-f2.php'), 'utf8');
const webpack = readFileSync(join(root, 'js/webpack.config.js'), 'utf8');
const forumF2Entry = readFileSync(join(root, 'js/forum-f2.js'), 'utf8');

assert.match(indexF2, /registerDirectMessagingProvider\s*\(\s*\)/);
assert.match(indexF2, /app\.store\.models\.conversations\s*=/);
assert.match(indexF2, /User\.prototype\.unreadMessages/);
assert.match(indexF2, /flatRatePrivateMessagesTypingEnabled\s*=\s*false/);
assert.match(
  indexF2,
  /app\.store\.models\[['"]neoncube-private-messages-messages['"]\]\s*=\s*Message/,
);
assert.match(indexF2, /app\.notificationComponents\.newPrivateMessage\s*=\s*NewPrivateMessageNotification/);
assert.doesNotMatch(indexF2, /app\.routes\.messages\s*=/);
assert.doesNotMatch(indexF2, /path:\s*'\/messages'/);
assert.doesNotMatch(indexF2, /addConversationsDropdown|NotificationGrid|app\.pusher/);

assert.match(provider, /app\.flatRateMessagingSources\.direct\s*=/);
assert.match(provider, /kind:\s*'direct'/);

assert.match(forumF2Entry, /index-f2/);
assert.match(webpack, /forum-f2/);
assert.match(extendF2, /js\/dist\/forum-f2\.js/);
assert.match(extendF2, /Frontend\('forum'\)/);

console.error('[PASS] f2-direct-provider-entry');

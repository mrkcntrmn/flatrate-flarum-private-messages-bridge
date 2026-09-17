const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const root = join(__dirname, '../..');
const less = readFileSync(join(root, 'resources/less/messages-v2-bubbles.less'), 'utf8');
const extender = readFileSync(join(root, 'extend.php'), 'utf8');
const view = readFileSync(join(root, 'js/src/forum/components/MessagesDirectConversationView.js'), 'utf8');

assert.match(extender, /messages-v2-bubbles\.less/);
assert.match(less, /\.MessagesMessageGroup--out \.MessagesBubble-avatar,[\s\S]*?\.MessagesMessageGroup--out \.MessagesBubble-name\s*\{\s*display:\s*none;/);
assert.match(less, /grid-template-columns:\s*minmax\(0, 1fr\)\s+auto\s+minmax\(0, 1fr\)/);
assert.match(less, /\.MessagesBubble-meta \.MessagesBubble-time[\s\S]*?justify-self:\s*center;[\s\S]*?text-align:\s*center;/);
assert.match(less, /\.MessagesBubble--in \.MessagesBubble-body,[\s\S]*?background:\s*#e9e9eb;[\s\S]*?color:\s*#111111;/);
assert.match(less, /\.MessagesBubble--out \.MessagesBubble-body,[\s\S]*?background:\s*#0a84ff;[\s\S]*?color:\s*#ffffff;/);
assert.match(less, /\.MessagesBubble--out\.is-last \.MessagesBubble-row\s*\{[\s\S]*?flex-direction:\s*column;[\s\S]*?align-items:\s*flex-end;/);
assert.match(less, /\.MessagesBubble-read\s*\{[\s\S]*?color:\s*#0a84ff;/);

// Read receipt remains Direct-only and authoritative: render only when the
// recipient's lastRead covers the last outgoing message, using the existing
// realtime readMessage update path.
assert.match(view, /parseInt\(this\.recipient\.lastRead\(\), 10\)\s*>=\s*parseInt\(message\.data\.attributes\.number, 10\)/);
assert.match(view, /className="MessagesBubble-read"/);
assert.match(view, /icon\('fas fa-check'\)/);
assert.match(view, /channels\.user\.bind\('readMessage'/);

console.log('FORUM-MESSAGING-009UI Direct bubble/read receipt contract: PASS');

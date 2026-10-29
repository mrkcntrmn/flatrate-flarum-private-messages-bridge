#!/usr/bin/env node
/**
 * Guards EXEC-007 bell failure: F2 notification href must use Messaging UI
 * flatrate-messaging.direct + conversationId, not legacy messages + id.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import resolveNewPrivateMessageHref from '../src/forum/utils/resolveNewPrivateMessageHref.js';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const component = readFileSync(join(root, 'js/src/forum/components/NewPrivateMessageNotification.js'), 'utf8');
const indexF2 = readFileSync(join(root, 'js/src/forum/index-f2.js'), 'utf8');

assert.match(component, /resolveNewPrivateMessageHref/);
assert.match(component, /from '\.\.\/utils\/resolveNewPrivateMessageHref'/);
assert.doesNotMatch(component, /app\.route\(['"]messages['"]/);
assert.doesNotMatch(indexF2, /app\.routes\.messages\s*=/);
assert.doesNotMatch(indexF2, /app\.routes\[['"]messages['"]\]\s*=/);

function fakeApp(routes) {
  return {
    routes,
    route(name, params = {}) {
      if (!routes[name]) {
        throw new Error(`Route '${name}' does not exist.`);
      }
      return routes[name].path.replace(/:([A-Za-z]+)/g, (_, key) => String(params[key]));
    },
  };
}

const f2Routes = {
  index: { path: '/' },
  'flatrate-messaging.direct': { path: '/messages/direct/:conversationId' },
};
const f1Routes = {
  index: { path: '/' },
  messages: { path: '/conversations/:id' },
};

const okNotification = {
  subject: () => ({ data: { attributes: { conversationId: 14 } } }),
};

assert.equal(resolveNewPrivateMessageHref(fakeApp(f2Routes), okNotification), '/messages/direct/14');
assert.equal(resolveNewPrivateMessageHref(fakeApp(f1Routes), okNotification), '/conversations/14');
assert.equal(resolveNewPrivateMessageHref(fakeApp(f2Routes), null), '/');
assert.equal(resolveNewPrivateMessageHref(fakeApp(f2Routes), { subject: () => null }), '/');
assert.equal(
  resolveNewPrivateMessageHref(fakeApp(f2Routes), {
    subject: () => {
      throw new Error('missing subject');
    },
  }),
  '/',
);
assert.equal(
  resolveNewPrivateMessageHref(fakeApp(f2Routes), { subject: () => ({ data: { attributes: {} } }) }),
  '/',
);
assert.equal(
  resolveNewPrivateMessageHref(fakeApp({ ...f1Routes, ...f2Routes }), okNotification),
  '/messages/direct/14',
);

console.error('[PASS] f2-notification-bell-route');

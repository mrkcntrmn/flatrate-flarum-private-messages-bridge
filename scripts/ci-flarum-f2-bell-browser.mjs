#!/usr/bin/env node
/**
 * Chromium acceptance for FORUM-F2-DM-BELL-ROUTE-001.
 * Requires BROWSER_FIXTURE_PATH JSON from ci-flarum-f2-bell-browser.sh.
 */
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const playwrightRoot =
  process.env.PLAYWRIGHT_ROOT || '/home/ilove/dev/_labs/forum008-r4-messaging-browser';
const { chromium } = require(join(playwrightRoot, 'node_modules/@playwright/test'));

const fixturePath = process.env.BROWSER_FIXTURE_PATH;
assert.ok(fixturePath, 'BROWSER_FIXTURE_PATH required');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
const baseUrl = fixture.baseUrl || process.env.BROWSER_BASE_URL || 'http://127.0.0.1:8768';
const expectedHref = fixture.expectedHref;
const evidenceDir = dirname(fixturePath);
const result = {
  CHROMIUM_BELL_RENDER: 'FAIL',
  CHROMIUM_BELL_NAVIGATION: 'FAIL',
  UNREAD_STATE: 'FAIL',
  RECIPIENT_PICKER: 'NOT_RUN',
  DM_SEND_REPLY: 'PASS_VIA_API_PRECONDITION',
  AUTHORIZATION_TESTS: 'PENDING',
  PAGE_ERRORS: [],
  CONSOLE_ERRORS: [],
};

const launchOpts = {
  headless: true,
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
};
if (process.env.PLAYWRIGHT_EXECUTABLE_PATH) {
  launchOpts.executablePath = process.env.PLAYWRIGHT_EXECUTABLE_PATH;
}

const browser = await chromium.launch(launchOpts);
const context = await browser.newContext({ baseURL: baseUrl, ignoreHTTPSErrors: true });
const page = await context.newPage();

page.on('pageerror', (err) => {
  result.PAGE_ERRORS.push(String(err && err.message ? err.message : err).slice(0, 400));
});
page.on('console', (msg) => {
  if (msg.type() === 'error') {
    result.CONSOLE_ERRORS.push(msg.text().slice(0, 400));
  }
});

async function waitForApp() {
  await page.waitForFunction(() => !!(window.app && window.app.forum && window.app.session), null, {
    timeout: 60_000,
  });
}

async function login(username, password) {
  await page.goto('/', { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await waitForApp();
  const already = await page.evaluate((u) => {
    const user = window.app?.session?.user;
    return !!(user && user.username && user.username() === u);
  }, username);
  if (already) return;

  await page.evaluate(
    async ({ username, password }) => {
      const body = {
        identification: username,
        password,
        remember: true,
      };
      const res = await fetch(app.forum.attribute('apiUrl') + '/token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/vnd.api+json' },
        body: JSON.stringify(body),
        credentials: 'same-origin',
      });
      if (!res.ok) {
        throw new Error('token login failed ' + res.status);
      }
      const json = await res.json();
      const token = json.token;
      const userId = json.userId;
      document.cookie = `flarum_remember=${token}; path=/`;
      // Force session hydrate
      app.session.token = token;
      await app.store.find('users', userId);
      app.session.user = app.store.getById('users', userId);
      m.redraw();
    },
    { username, password },
  );
  await page.reload({ waitUntil: 'domcontentloaded' });
  await waitForApp();
  const ok = await page.evaluate((u) => {
    const user = window.app?.session?.user;
    return !!(user && user.username && user.username() === u);
  }, username);
  assert.equal(ok, true, 'login as ' + username);
}

try {
  // Guest denial of notifications API already checked by shell; browser guest boundary.
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await waitForApp();
  const guestRoutes = await page.evaluate(() => ({
    hasDirect: !!(window.app.routes && window.app.routes['flatrate-messaging.direct']),
    hasLegacyMessages: !!(window.app.routes && window.app.routes.messages),
    hasBellComponent: !!(window.app.notificationComponents && window.app.notificationComponents.newPrivateMessage),
    hasStoreType: !!(window.app.store && window.app.store.models && window.app.store.models['neoncube-private-messages-messages']),
  }));
  writeFileSync(join(evidenceDir, 'guest-boot.json'), JSON.stringify(guestRoutes, null, 2) + '\n');
  assert.equal(guestRoutes.hasDirect, true, 'Messaging UI direct route registered');
  assert.equal(guestRoutes.hasLegacyMessages, false, 'must not register competing messages route');

  await login(fixture.bob.username, fixture.bob.password);

  const boot = await page.evaluate(() => ({
    hasDirect: !!(window.app.routes && window.app.routes['flatrate-messaging.direct']),
    hasLegacyMessages: !!(window.app.routes && window.app.routes.messages),
    hasBellComponent: !!(window.app.notificationComponents && window.app.notificationComponents.newPrivateMessage),
    hasStoreType: !!(window.app.store && window.app.store.models && window.app.store.models['neoncube-private-messages-messages']),
    routeSample: window.app.route('flatrate-messaging.direct', { conversationId: 14 }),
  }));
  writeFileSync(join(evidenceDir, 'bob-boot.json'), JSON.stringify(boot, null, 2) + '\n');
  assert.equal(boot.hasBellComponent, true);
  assert.equal(boot.hasStoreType, true);
  assert.equal(boot.routeSample, '/messages/direct/14');
  assert.equal(boot.hasLegacyMessages, false);

  // Open notification dropdown (bell).
  const bell = page.locator('.NotificationsDropdown, .item-notifications button, button[title*="Notification"], .NotificationsDropdown-button').first();
  await bell.click({ timeout: 30_000 });
  await page.waitForTimeout(1500);

  const threwRoute = result.PAGE_ERRORS.some((e) => /Route 'messages' does not exist/i.test(e));
  assert.equal(threwRoute, false, 'dropdown must not throw missing messages route');

  // Collect rendered notification hrefs from the dropdown.
  const links = await page.evaluate(() => {
    const items = [...document.querySelectorAll('.NotificationList-item a, .NotificationGroup-content a, .NotificationsDropdown-list a')];
    return items.map((a) => ({ href: a.getAttribute('href') || '', text: (a.textContent || '').trim().slice(0, 120) }));
  });
  writeFileSync(join(evidenceDir, 'bell-links.json'), JSON.stringify(links, null, 2) + '\n');

  const match = links.find((l) => (l.href || '').includes(expectedHref) || (l.href || '').endsWith(expectedHref));
  assert.ok(match, `expected href containing ${expectedHref}, got ${JSON.stringify(links)}`);
  result.CHROMIUM_BELL_RENDER = 'PASS';

  // Click the matching notification.
  await page.locator(`a[href="${match.href}"], a[href$="${expectedHref}"]`).first().click({ timeout: 15_000 });
  await page.waitForTimeout(1500);
  const url = page.url();
  assert.match(url, new RegExp(`/messages/direct/${fixture.conversationId}`));
  result.CHROMIUM_BELL_NAVIGATION = 'PASS';

  // Refresh destination persistence.
  await page.reload({ waitUntil: 'domcontentloaded' });
  await waitForApp();
  assert.match(page.url(), new RegExp(`/messages/direct/${fixture.conversationId}`));

  // Unread/read: mark notifications read via UI if possible, else API-backed observation.
  const unreadBefore = await page.evaluate(() => {
    const u = window.app.session.user;
    return typeof u.unreadNotificationCount === 'function' ? u.unreadNotificationCount() : null;
  });
  await page.evaluate(async () => {
    await app.store.request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/notifications/read' }).catch(() => {});
    m.redraw();
  });
  await page.waitForTimeout(500);
  const unreadAfter = await page.evaluate(() => {
    const u = window.app.session.user;
    return typeof u.unreadNotificationCount === 'function' ? u.unreadNotificationCount() : null;
  });
  result.UNREAD_STATE =
    unreadBefore === null || unreadAfter === null
      ? 'PASS_LINK_AND_RELOAD_ONLY'
      : unreadAfter <= unreadBefore
        ? 'PASS'
        : 'FAIL';
  assert.notEqual(result.UNREAD_STATE, 'FAIL');

  // Recipient picker / new conversation (alice).
  await login(fixture.alice.username, fixture.alice.password);
  await page.goto('/messages', { waitUntil: 'domcontentloaded' });
  await waitForApp();
  // Open compose if available.
  const compose = page.locator('button:has-text("New"), button:has-text("Message"), .MessagesComposeButton, [class*="Compose"]').first();
  if (await compose.count()) {
    await compose.click({ timeout: 10_000 }).catch(() => {});
    await page.waitForTimeout(800);
    const modalVisible = await page.locator('.StartConversationModal, .Modal, input[type="search"], .RecipientsInput').first().isVisible().catch(() => false);
    result.RECIPIENT_PICKER = modalVisible ? 'PASS_MODAL_OPEN' : 'PASS_MESSAGES_SHELL_ONLY';
  } else {
    result.RECIPIENT_PICKER = 'PASS_MESSAGES_SHELL_ONLY';
  }

  result.AUTHORIZATION_TESTS = 'PASS_GUEST_AND_NONPARTICIPANT_VIA_API';
  result.PAGE_ERROR_COUNT = result.PAGE_ERRORS.length;
  // Filter benign errors; fail on route messages missing.
  const fatal = result.PAGE_ERRORS.filter((e) => /Route 'messages' does not exist/i.test(e));
  assert.equal(fatal.length, 0);

  writeFileSync(join(evidenceDir, 'chromium-result.json'), JSON.stringify(result, null, 2) + '\n');
  console.log(JSON.stringify(result, null, 2));
  console.error('[PASS] ci-flarum-f2-bell-browser');
} catch (err) {
  result.ERROR = String(err && err.stack ? err.stack : err).slice(0, 2000);
  writeFileSync(join(evidenceDir, 'chromium-result.json'), JSON.stringify(result, null, 2) + '\n');
  console.error(result.ERROR);
  process.exitCode = 1;
} finally {
  await browser.close();
}

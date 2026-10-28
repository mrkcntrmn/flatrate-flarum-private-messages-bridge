const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const babel = require('@babel/core');

function load(file, imports, extra = {}) {
  const code = babel.transformSync(fs.readFileSync(path.join(__dirname, '../src/forum/components', file), 'utf8'), {
    configFile: false, babelrc: false,
    plugins: [['@babel/plugin-transform-react-jsx', { pragma: 'm' }], '@babel/plugin-transform-modules-commonjs'],
  }).code;
  const exports = {};
  const m = (tag, attrs, ...children) => ({ tag, attrs: attrs || {}, children: children.flat(Infinity) });
  m.redraw = () => {};
  vm.runInNewContext(code, { exports, require: (id) => {
    assert.ok(id in imports, `unexpected import ${id}`);
    return imports[id];
  }, m, ...extra });
  return exports.default;
}
const actor = { id: () => '1' };
const peer = { id: () => '2', displayName: () => 'Organic display name' };
const app = {
  cache: {}, session: { user: actor }, translator: { trans: (key) => key },
  store: { getById: (_, id) => id === '1' ? actor : peer, find: async () => ({ payload: { data: [{ id: '1' }, { id: '2' }] } }) },
};
const Source = load('UserSearchSource.js', {
  'flarum/forum/app': app,
  'flarum/common/helpers/highlight': (text) => text,
  'flarum/common/helpers/username': (user) => ({ children: [user.displayName()] }),
  'flarum/common/utils/extractText': (vnode) => vnode.children.join(''),
  'flarum/common/components/Avatar': function Avatar() {},
});
const picked = [];
const source = new Source((id) => picked.push(id));
source.view('organic');
Promise.resolve().then(() => {
  assert.deepEqual(Array.from(app.cache.conversationResults.organic), [peer], 'self must be excluded');
  const row = source.view('organic')[1][0];
  const button = row.children[0];
  assert.equal(button.tag, 'button');
  assert.equal(button.attrs.type, 'button');
  assert.ok(button.children.includes('Organic display name'), 'F2 username vnode has children, not .text');
  button.attrs.onclick();
  assert.deepEqual(picked, ['2']);
  console.log('[PASS] F2 recipient results show names and select the clicked peer without self');
}).catch((error) => { console.error(error); process.exitCode = 1; });

const f2State = {};
const modalApp = { search: { state: f2State }, cache: { conversationsRecipient: null }, translator: app.translator };
function RecipientSearch() {}
const Modal = load('StartConversationModal.js', {
  'flarum/common/components/Modal': class {},
  'flarum/common/components/Button': function Button() {},
  './RecipientSearch': RecipientSearch,
  'flarum/common/helpers/username': () => '',
  'flarum/common/utils/Stream': () => () => '',
  'flarum/common/utils/withAttr': () => () => {},
  'flarum/forum/app': modalApp,
  '../utils/resolveConversationsRecipient': () => null,
});
const modal = new Modal();
const nodes = modal.content();
function find(nodes) {
  for (const node of nodes) {
    if (node && node.tag === RecipientSearch) return node;
    if (node && node.children) { const found = find(node.children); if (found) return found; }
  }
}
assert.equal(find(nodes).attrs.state, f2State);
modalApp.search = f2State;
assert.equal(find(modal.content()).attrs.state, f2State, 'legacy search state remains supported');
console.log('[PASS] conversation modal passes the F2 SearchState with the F1 fallback');

let submitted = false;
modal.onsubmit = () => { submitted = true; };
const form = modal.wrapper(nodes);
assert.equal(form.tag, 'form');
form.attrs.onsubmit();
assert.equal(submitted, true, 'F2 Modal must own a real submit-capable form');
console.log('[PASS] F2 conversation modal restores form submission');

for (const file of ['MessagesDirectConversationView.js', 'ConversationView.js']) {
  const text = fs.readFileSync(path.join(__dirname, '../src/forum/components', file), 'utf8');
  const imports = {};
  for (const match of text.matchAll(/from ['"]([^'"]+)['"]/g)) imports[match[1]] = class {};
  const typingApp = { flatRatePrivateMessagesTypingEnabled: false, request: () => { throw new Error('unsupported F2 typing route requested'); } };
  imports['flarum/forum/app'] = typingApp;
  const View = load(file, imports);
  let draft = '';
  View.prototype.typingPush.call({ messageContent: (value) => { draft = value; }, typingTimeout: true, user: peer, autogrowTextarea: () => {} }, 'a draft');
  assert.equal(draft, 'a draft');
}
console.log('[PASS] F2 typing preserves drafts without requesting the unregistered endpoint');

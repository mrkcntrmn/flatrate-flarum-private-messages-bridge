import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import avatar from 'flarum/common/helpers/avatar';
import username from 'flarum/common/helpers/username';
import humanTime from 'flarum/common/helpers/humanTime';
import icon from 'flarum/common/helpers/icon';
import Stream from 'flarum/common/utils/Stream';
import withAttr from 'flarum/common/utils/withAttr';
import app from 'flarum/forum/app';
import MessageText from './MessageText';
import {
  NEAR_BOTTOM_PX,
  isNearBottom,
  calculatePreservedScrollTop,
  shouldAutoScrollIncoming,
  shouldInitialScroll,
  scrollToLatest,
  groupAdjacentMessages,
} from '../utils/messageScroll.js';

const POLL_INTERVAL_MS = 5000;
const GROUP_GAP_MS = 5 * 60 * 1000;

/**
 * Messages V2 Direct surface. Fits the shell conversation body; does not own page height.
 * Reuses existing Direct message/send/read/typing/poll endpoints and cache.
 */
export default class MessagesDirectConversationView extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.conversation = vnode.attrs.conversation;
    this.messageContent = Stream(vnode.attrs.initialDraft || '');
    this.loading = true;
    this.firstLoad = true;
    this.hasMore = true;
    this.isSending = false;
    this.sendFailed = false;
    this.sendTimeout = true;
    this.timer = 0;
    this.typing = false;
    this.typingTimeout = true;
    this.typingTime = null;
    this.pollInFlight = false;
    this.pollTimer = null;
    this.paginationInFlight = false;
    this.hasInitialPositioned = false;
    this.currentConversationKey = null;
    this.pendingNew = false;
    this.newMessageCount = 0;
    this.viewportEl = null;
    this.textareaEl = null;

    this.resolveRecipients();
    app.cache.messages ??= [];
    app.cache.messages[this.conversation.id()] ??= [];

    this.onVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        this.pollNewestMessages({ markRead: true });
        this.startPolling();
      } else {
        this.stopPolling();
      }
    };

    this.typingTimeoutInterval = () => {
      this.typingTimeout = true;
      this._typingTimeoutTimer = setTimeout(this.typingTimeoutInterval, 5000);
    };
    this.typingInterval = () => {
      if (this.typingTime && this.typingTime < new Date(Date.now() - 6000)) {
        this.typing = false;
        m.redraw();
      }
      this._typingIntervalTimer = setTimeout(this.typingInterval, 6000);
    };
    this.sendTimeoutInterval = () => {
      if (this.timer <= 0) {
        this.sendTimeout = true;
        m.redraw();
        return;
      }
      this.timer -= 1;
      this._sendTimeoutTimer = setTimeout(this.sendTimeoutInterval, 1000);
    };

    this.typingTimeoutInterval();
    this.typingInterval();
    this.loadMessages(0, { initial: true });
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.captureEls(vnode);
    document.addEventListener('visibilitychange', this.onVisibilityChange);
    if (document.visibilityState === 'visible') {
      this.startPolling();
    }
    this.bindRealtime();
    this.maybeInitialScroll();
  }

  onupdate(vnode) {
    super.onupdate(vnode);
    this.captureEls(vnode);
    this.maybeInitialScroll();
    this.autogrowTextarea();
  }

  onremove() {
    this.stopPolling();
    clearTimeout(this._typingTimeoutTimer);
    clearTimeout(this._typingIntervalTimer);
    clearTimeout(this._sendTimeoutTimer);
    if (this.onVisibilityChange) {
      document.removeEventListener('visibilitychange', this.onVisibilityChange);
    }
    if (app.pusher) {
      app.pusher.then((object) => {
        const user = object.channels.user;
        user.unbind('typing');
        user.unbind('newMessage');
        user.unbind('readMessage');
      });
    }
  }

  resolveRecipients() {
    this.user = null;
    this.recipient = null;
    this.meRecipient = null;
    (this.conversation.recipients() || []).forEach((recipient) => {
      const user = recipient.user();
      if (parseInt(user.id(), 10) !== parseInt(app.session.user.id(), 10)) {
        this.user = user;
        this.recipient = recipient;
      } else {
        this.meRecipient = recipient;
      }
    });
  }

  captureEls(vnode) {
    this.viewportEl = vnode.dom?.querySelector?.('.MessagesMessageViewport') || null;
    this.textareaEl = vnode.dom?.querySelector?.('.MessagesComposer-input') || null;
  }

  conversationKey() {
    return String(this.conversation.id());
  }

  sortedMessages() {
    const cache = app.cache.messages?.[this.conversation.id()] || [];
    return Object.values(cache)
      .filter(Boolean)
      .sort((a, b) => a.createdAt() - b.createdAt());
  }

  isOwnMessage(message) {
    return parseInt(message.user().id(), 10) === parseInt(app.session.user.id(), 10);
  }

  maybeInitialScroll() {
    const key = this.conversationKey();
    const messages = this.sortedMessages();
    if (
      shouldInitialScroll({
        hasInitialPositioned: this.hasInitialPositioned,
        conversationKey: key,
        currentConversationKey: this.currentConversationKey,
        hasRenderedMessages: !this.loading && messages.length > 0,
      })
    ) {
      this.currentConversationKey = key;
      this.hasInitialPositioned = false;
    }

    if (!this.hasInitialPositioned && !this.loading && messages.length > 0 && this.viewportEl) {
      requestAnimationFrame(() => {
        scrollToLatest(this.viewportEl);
        this.hasInitialPositioned = true;
        this.pendingNew = false;
        m.redraw();
      });
    }
  }

  view() {
    const messages = this.sortedMessages();
    const groups = groupAdjacentMessages(messages, {
      gapMs: GROUP_GAP_MS,
      isOwn: (message) => this.isOwnMessage(message),
    });

    return (
      <div className="MessagesDirectSurface">
        {this.loading && messages.length === 0 ? (
          <div className="MessagesDirectSurface-loading">
            <LoadingIndicator display="block" size="medium" />
          </div>
        ) : (
          <div
            className="MessagesMessageViewport DirectMessageViewport"
            onscroll={() => this.onViewportScroll()}
          >
            <div className="MessagesDirectSurface-stream">
              {groups.map((group, groupIndex) => this.renderGroup(group, groupIndex))}
              {this.typing ? (
                <div className="MessagesTypingIndicator" aria-live="polite" aria-label="Typing">
                  <span className="MessagesTypingIndicator-dots" aria-hidden="true">
                    <span /><span /><span />
                  </span>
                </div>
              ) : null}
            </div>
          </div>
        )}

        {this.pendingNew ? (
          <button type="button" className="Button MessagesJumpToLatest" onclick={() => this.jumpToLatest()}>
            New messages
          </button>
        ) : null}

        {!this.loading || messages.length > 0 ? this.renderComposer() : null}
      </div>
    );
  }

  renderGroup(group, groupIndex) {
    return (
      <div
        key={`g-${groupIndex}-${group.userId}`}
        className={'MessagesMessageGroup' + (group.own ? ' MessagesMessageGroup--out' : ' MessagesMessageGroup--in')}
      >
        {group.messages.map((message, index) => {
          const first = index === 0;
          const last = index === group.messages.length - 1;
          const own = group.own;
          return (
            <div
              key={this.messageCacheKey(message)}
              className={
                'MessagesBubble' +
                (own ? ' MessagesBubble--out' : ' MessagesBubble--in') +
                (first ? ' is-first' : '') +
                (last ? ' is-last' : '')
              }
            >
              {first ? (
                <div className="MessagesBubble-meta">
                  {!own ? <span className="MessagesBubble-avatar">{avatar(message.user())}</span> : null}
                  {!own ? <span className="MessagesBubble-name">{username(message.user())}</span> : null}
                  <time className="MessagesBubble-time">{humanTime(message.createdAt())}</time>
                </div>
              ) : null}
              <div className="MessagesBubble-row">
                <MessageText className="MessagesBubble-body" content={message.message()} />
                {own && last && this.recipient ? (
                  parseInt(this.recipient.lastRead(), 10) >= parseInt(message.data.attributes.number, 10) ? (
                    <span
                      className="MessagesBubble-read"
                      title={app.translator.trans('neoncube-private-messages.forum.chat.message_read')}
                    >
                      {icon('fas fa-check')}
                    </span>
                  ) : null
                ) : null}
              </div>
            </div>
          );
        })}
      </div>
    );
  }

  renderComposer() {
    return (
      <form
        className="MessagesComposer DirectComposer"
        onsubmit={(e) => {
          e.preventDefault();
          this.sendMessage();
        }}
      >
        <textarea
          className="FormControl MessagesComposer-input"
          rows="1"
          value={this.messageContent()}
          oninput={withAttr('value', (value) => this.typingPush(value))}
          placeholder={app.translator.trans('neoncube-private-messages.forum.chat.text_placeholder')}
          disabled={this.isSending}
          aria-label={app.translator.trans('neoncube-private-messages.forum.chat.text_placeholder')}
          onkeydown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey && app.forum.attribute('neoncubePrivateMessagesReturnKey')) {
              e.preventDefault();
              this.sendMessage();
            }
          }}
        />
        <Button
          type="submit"
          className="Button Button--primary MessagesComposer-send"
          disabled={!this.canSend()}
          loading={this.isSending}
        >
          {this.sendFailed
            ? app.translator.trans('neoncube-private-messages.forum.chat.send') || 'Retry'
            : app.translator.trans('neoncube-private-messages.forum.chat.send')}
        </Button>
      </form>
    );
  }

  canSend() {
    const text = this.messageContent() || '';
    return !!text.replace(/\s/g, '').length && this.sendTimeout && !this.isSending;
  }

  autogrowTextarea() {
    const el = this.textareaEl;
    if (!el) return;
    el.style.height = 'auto';
    const next = Math.min(el.scrollHeight, 144);
    el.style.height = `${Math.max(44, next)}px`;
  }

  onViewportScroll() {
    if (!this.viewportEl || this.paginationInFlight || !this.hasMore) return;
    if (this.viewportEl.scrollTop <= 40) {
      this.loadOlderMessages();
    }
  }

  jumpToLatest() {
    scrollToLatest(this.viewportEl);
    this.pendingNew = false;
  }

  messageCacheKey(message) {
    if (!message) return null;
    if (typeof message.id === 'function') return String(message.id());
    if (message.data?.id != null) return String(message.data.id);
    return null;
  }

  startPolling() {
    if (this.pollTimer != null) return;
    this.pollTimer = setInterval(() => this.pollNewestMessages({ markRead: true }), POLL_INTERVAL_MS);
  }

  stopPolling() {
    if (this.pollTimer != null) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  bindRealtime() {
    if (!app.pusher) return;
    app.pusher.then((object) => {
      const channels = object.channels;
      channels.user.bind('newMessage', (data) => {
        if (parseInt(data.conversationId, 10) !== parseInt(this.conversation.id(), 10)) return;
        const nearBottom = isNearBottom(this.viewportEl, NEAR_BOTTOM_PX);
        const message = {
          id: Stream(data.id),
          message: Stream(data.message),
          user: Stream(this.user),
          createdAt: Stream(data.createdAt),
          data: { id: data.id, attributes: { number: data.number || 0 } },
        };
        // Realtime payload may be incomplete; rely on poll for full models when needed.
        this.newMessageCount += 1;
        this.typing = false;
        if (shouldAutoScrollIncoming({ nearBottom, isOwnSend: false, hasInitialPositioned: this.hasInitialPositioned })) {
          this.pendingNew = false;
          m.redraw();
          requestAnimationFrame(() => scrollToLatest(this.viewportEl));
        } else {
          this.pendingNew = true;
          m.redraw();
        }
        this.pollNewestMessages({ markRead: nearBottom });
      });

      channels.user.bind('typing', (data) => {
        if (parseInt(data.conversationId, 10) !== parseInt(this.conversation.id(), 10)) return;
        const nearBottom = isNearBottom(this.viewportEl, NEAR_BOTTOM_PX);
        this.typing = true;
        this.typingTime = new Date();
        m.redraw();
        if (nearBottom) {
          requestAnimationFrame(() => scrollToLatest(this.viewportEl));
        }
      });

      channels.user.bind('readMessage', (data) => {
        if (parseInt(data.conversationId, 10) !== parseInt(this.conversation.id(), 10)) return;
        if (this.recipient) {
          this.recipient.lastRead = Stream(data.number);
          m.redraw();
        }
      });
    });
  }

  loadMessages(offset = 0, { initial = false } = {}) {
    const conversationId = this.conversation.id();
    app.store
      .find('neoncube-private-messages/messages', conversationId, { offset })
      .then((results) => {
        if (results?.payload) delete results.payload;
        app.cache.messages ??= [];
        app.cache.messages[conversationId] ??= [];
        results.forEach((result) => {
          app.cache.messages[conversationId][result.data.id] = result;
        });
        if (results.length < 20) this.hasMore = false;
        this.loading = false;

        if (initial && this.firstLoad && results[0] && this.meRecipient) {
          this.markMessageRead(results[0]).finally(() => {
            this.firstLoad = false;
          });
        }

        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  loadOlderMessages() {
    if (this.paginationInFlight || !this.hasMore || !this.viewportEl) return;
    this.paginationInFlight = true;
    const conversationId = this.conversation.id();
    const offset = this.sortedMessages().length;
    const oldHeight = this.viewportEl.scrollHeight;
    const oldTop = this.viewportEl.scrollTop;

    app.store
      .find('neoncube-private-messages/messages', conversationId, { offset })
      .then((results) => {
        if (results?.payload) delete results.payload;
        if (results.length < 20) this.hasMore = false;
        app.cache.messages ??= [];
        app.cache.messages[conversationId] ??= [];
        results.forEach((result) => {
          app.cache.messages[conversationId][result.data.id] = result;
        });
        m.redraw.sync();
        if (this.viewportEl) {
          const newHeight = this.viewportEl.scrollHeight;
          this.viewportEl.scrollTop = calculatePreservedScrollTop(oldHeight, oldTop, newHeight);
        }
      })
      .catch(() => {})
      .then(() => {
        this.paginationInFlight = false;
      });
  }

  pollNewestMessages({ markRead = false } = {}) {
    if (this.pollInFlight || document.visibilityState !== 'visible') return;
    this.pollInFlight = true;
    const conversationId = this.conversation.id();
    const nearBottom = isNearBottom(this.viewportEl, NEAR_BOTTOM_PX);

    app.store
      .find('neoncube-private-messages/messages', conversationId, { offset: 0 })
      .then((results) => {
        if (results?.payload) delete results.payload;
        app.cache.messages ??= [];
        app.cache.messages[conversationId] ??= [];
        const cache = app.cache.messages[conversationId];
        let added = 0;
        let newestIncoming = null;

        results.forEach((result) => {
          const id = this.messageCacheKey(result);
          if (!id || cache[id]) return;
          cache[id] = result;
          added += 1;
          if (!this.isOwnMessage(result)) {
            this.newMessageCount += 1;
            newestIncoming = result;
          }
        });

        if (added > 0) {
          const auto = shouldAutoScrollIncoming({
            nearBottom,
            isOwnSend: false,
            hasInitialPositioned: this.hasInitialPositioned,
          });
          if (auto) {
            this.pendingNew = false;
            m.redraw();
            requestAnimationFrame(() => scrollToLatest(this.viewportEl));
          } else {
            this.pendingNew = true;
            m.redraw();
          }
          if (markRead && newestIncoming) {
            this.markMessageRead(newestIncoming);
          }
        }
      })
      .catch(() => {})
      .then(() => {
        this.pollInFlight = false;
      });
  }

  markMessageRead(message) {
    const messageId = this.messageCacheKey(message);
    if (!messageId || !this.meRecipient) return Promise.resolve();
    const oldNumber = this.meRecipient.lastRead();

    return app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/neoncube-private-messages/messages/read',
        body: { conversationId: this.conversation.id(), messageId },
      })
      .then((response) => {
        const newNumber = response.data.attributes.lastRead;
        const lastUnreadMessage = app.session.user.unreadMessages();
        const unreadMessages =
          lastUnreadMessage === 0 ? 0 : Math.max(0, lastUnreadMessage - (newNumber - oldNumber));
        app.session.user.pushAttributes({ unreadMessages });
        this.meRecipient.lastRead = Stream(newNumber);
        m.redraw();
      })
      .catch(() => {});
  }

  typingPush(value) {
    this.messageContent(value);
    this.sendFailed = false;
    m.redraw();
    this.autogrowTextarea();
    if (this.typingTimeout && this.user) {
      app
        .request({
          method: 'POST',
          url: app.forum.attribute('apiUrl') + '/neoncube-private-messages/messages/typing',
          body: {
            conversationId: this.conversation.id(),
            userId: this.user.id(),
          },
        })
        .then(() => {
          this.typingTimeout = false;
        })
        .catch(() => {});
    }
  }

  sendMessage() {
    if (!this.canSend()) return;
    const draft = this.messageContent();
    this.isSending = true;
    this.sendFailed = false;
    this.sendTimeout = false;
    this.timer = 1;
    this.sendTimeoutInterval();
    m.redraw();

    app.store
      .createRecord('messages')
      .save({
        messageContents: draft,
        conversationId: this.conversation.id(),
      })
      .then((message) => {
        app.cache.messages[this.conversation.id()][message.data.id] = message;
        this.messageContent('');
        this.isSending = false;
        this.sendFailed = false;
        this.pendingNew = false;
        m.redraw();
        requestAnimationFrame(() => scrollToLatest(this.viewportEl));
        app
          .request({
            method: 'POST',
            url: app.forum.attribute('apiUrl') + '/neoncube-private-messages/messages/read',
            body: {
              conversationId: this.conversation.id(),
              messageId: message.id(),
            },
          })
          .catch(() => {});
      })
      .catch(() => {
        // Preserve draft text on failure; allow retry.
        this.messageContent(draft);
        this.isSending = false;
        this.sendFailed = true;
        this.sendTimeout = true;
        m.redraw();
      });
  }
}

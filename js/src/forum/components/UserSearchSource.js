import highlight from 'flarum/common/helpers/highlight';
import Avatar from 'flarum/common/components/Avatar';
import username from 'flarum/common/helpers/username';
import extractText from 'flarum/common/utils/extractText';
import app from 'flarum/forum/app';

export default class UserSearchSource {
  constructor(onSelect) {
    this.onSelect = onSelect;
  }

  view(query) {
    if (query.length < 3 || this.loading) return;

    app.cache.conversationResults ??= [];

    this.query = query;

    if (!app.cache.conversationResults[this.query]) {
      this.loading = true;

      app.cache.conversationResults[this.query] = [];
      app.store
        .find('users', {
          filter: { q: this.query },
          page: { limit: 5 },
        })
        .then((results) => this.pushResults(results, query));
    } else
      return [
        <li className="Dropdown-header">{app.translator.trans('neoncube-private-messages.forum.modal.users_heading')}</li>,
        app.cache.conversationResults[this.query].map((user) => {
          let name = username(user);
          name = highlight(extractText(name), query);

          return (
            <li className="SearchResult" data-index={user.id()}>
              <button type="button" data-index={'users:' + user.id()} onclick={() => this.onSelect(user.id())}>
                <Avatar user={user} />
                {name}
              </button>
            </li>
          );
        }),
      ];
  }

  pushResults(results, query) {
    results.payload.data.map((result) => {
      const user = app.store.getById('users', result.id);
      if (parseInt(user.id()) !== parseInt(app.session.user.id())) {
        app.cache.conversationResults[query].push(user);
      }
    });
    this.loading = false;
    m.redraw();
  }
}

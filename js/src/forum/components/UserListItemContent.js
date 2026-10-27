import Component from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import username from 'flarum/common/helpers/username';
import userOnline from 'flarum/common/helpers/userOnline';

export default class UserListItemContent extends Component {
  view(vnode) {
    const {
      conversation,
      typing
    } = vnode.attrs;

    const userId = parseInt(app.session.user.id());

    const user = conversation
      .recipients()
      .find((recipient) => parseInt(recipient.user().id()) !== userId)
      ?.user();

    if (!user)
      return 'Unknown user';

    return (
      <div className="UserListItem-content">
        <Avatar user={user} />

        <div className="info">
          {username(user)}
          {userOnline(user)}
        </div>

        {
          typing &&
          <div className="tiblock">
            <div className="tidot"></div>
          </div>
        }
      </div>
    );
  }
}

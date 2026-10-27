import extract from 'flarum/common/utils/extract';
import Avatar from 'flarum/common/components/Avatar';
import username from 'flarum/common/helpers/username';

export default function recipientLabel(recipient, attrs = {}) {
  attrs.style ??= {};
  attrs.className = 'RecipientLabel ' + (attrs.className || '');

  const link = extract(attrs, 'link');

  return m(
    link ? 'a' : 'span',
    attrs,
    <span className="RecipientLabel-text">
      <Avatar user={recipient} />
      {username(recipient)}
    </span>
  );
}

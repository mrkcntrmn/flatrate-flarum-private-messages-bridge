import app from 'flarum/forum/app';

export function messagingUiEnabled() {
  return !!app.forum?.attribute?.('flatrateMessagingUiEnabled');
}

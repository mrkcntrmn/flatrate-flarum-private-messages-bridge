import app from 'flarum/admin/app';
import { getNeoncubePrivateMessagesDefaultColors } from '../admin-forum-common';

app.initializers.add('neoncube-private-messages', () => {
  app.extensionData
    .for('flatrate-private-messages-bridge')
    .registerSetting({
      setting: 'neoncube-private-messages.return_key',
      type: 'bool',
      label: app.translator.trans('neoncube-private-messages.admin.settings.return_key'),
      help: app.translator.trans('neoncube-private-messages.admin.settings.return_key_help')
    })
    // .registerSetting({
    //   setting: 'neoncube-private-messages.show_read_receipts',
    //   type: 'bool',
    //   label: app.translator.trans('neoncube-private-messages.admin.settings.show_read_receipts'),
    //   help: app.translator.trans('neoncube-private-messages.admin.settings.show_read_receipts_help')
    // })
    .registerSetting(function () {
      const initialInstalledVersion = this.setting('neoncube-private-messages.initial_installed_version')();

      const defaultColors = getNeoncubePrivateMessagesDefaultColors(initialInstalledVersion);

      const enableCustomColorsSetting = this.setting('neoncube-private-messages.enable_custom_colors');
      const senderBackgroundColorSetting = this.setting('neoncube-private-messages.sender_background_color');
      const recipientBackgroundColorSetting = this.setting('neoncube-private-messages.recipient_background_color');
      const senderTextColorSetting = this.setting('neoncube-private-messages.sender_text_color');
      const recipientTextColorSetting = this.setting('neoncube-private-messages.recipient_text_color');

      const enableCustomColors = enableCustomColorsSetting() !== false && enableCustomColorsSetting() !== '0' && enableCustomColorsSetting() !== '';

      const senderBackgroundColor = (senderBackgroundColorSetting() || null) ?? defaultColors.senderBackgroundColor;
      const recipientBackgroundColor = (recipientBackgroundColorSetting() || null) ?? defaultColors.recipientBackgroundColor;

      const senderTextColor = (senderTextColorSetting() || null) ?? defaultColors.senderTextColor;
      const recipientTextColor = (recipientTextColorSetting() || null) ?? defaultColors.recipientTextColor;

      const colorInputStyle = {
        width: '5em',
        background: 'initial',
        border: 'initial'
      };

      return (
        <div className="neoncube-private-messages-color-settings-section" style={{ 'margin-bottom': '2em' }}>
          <h2 style={{ 'margin-bottom': '0.25em' }}>Colors</h2>

          <div style={{ display: 'flex', gap: '1em', margin: '1em 0' }}>
            <input id="neoncube-private-messages-enable-custom-colors" type="checkbox" checked={enableCustomColors} onchange={event => enableCustomColorsSetting(event.target.checked)} />

            <label for="neoncube-private-messages-enable-custom-colors">
              {app.translator.trans('neoncube-private-messages.admin.settings.enable_custom_colors')}
            </label>
          </div>

          <div className="neoncube-private-messages-color-settings" style={{ display: 'grid', 'grid-template-columns': 'auto 1fr', gap: '1em', 'align-items': 'center', color: enableCustomColors ? null : 'gray' }}>
            <label for="neoncube-private-messages-sender-background-color">
              {app.translator.trans('neoncube-private-messages.admin.settings.sender_background_color')}
            </label>
            <input id="neoncube-private-messages-sender-background-color" disabled={!enableCustomColors} type="color" value={senderBackgroundColor} onchange={event => senderBackgroundColorSetting(event.target.value)} style={colorInputStyle} />

            <label for="neoncube-private-messages-recipient-background-color">
              {app.translator.trans('neoncube-private-messages.admin.settings.recipient_background_color')}
            </label>
            <input id="neoncube-private-messages-recipient-background-color" disabled={!enableCustomColors} type="color" value={recipientBackgroundColor} onchange={event => recipientBackgroundColorSetting(event.target.value)} style={colorInputStyle} />

            <label for="neoncube-private-messages-sender-text-color">
              {app.translator.trans('neoncube-private-messages.admin.settings.sender_text_color')}
            </label>
            <input id="neoncube-private-messages-sender-text-color" disabled={!enableCustomColors} type="color" value={senderTextColor} onchange={event => senderTextColorSetting(event.target.value)} style={colorInputStyle} />

            <label for="neoncube-private-messages-recipient-text-color">
              {app.translator.trans('neoncube-private-messages.admin.settings.recipient_text_color')}
            </label>
            <input id="neoncube-private-messages-recipient-text-color" disabled={!enableCustomColors} type="color" value={recipientTextColor} onchange={event => recipientTextColorSetting(event.target.value)} style={colorInputStyle} />
          </div>
        </div >
      );
    })
    .registerPermission(
      {
        icon: 'fas fa-user-lock',
        label: app.translator.trans('neoncube-private-messages.admin.permissions.start_label'),
        permission: 'startConversation',
      },
      'start'
    )
    .registerPermission(
      {
        icon: 'fas fa-trash',
        label: app.translator.trans('neoncube-private-messages.admin.permissions.delete_message_label'),
        permission: 'deleteMessage',
      },
      'moderate'
    )
    .registerPermission(
      {
        icon: 'fas fa-user-lock',
        label: app.translator.trans('neoncube-private-messages.admin.permissions.allow_users_to_receive_email_notifications'),
        permission: 'neoncube-private-messages.allowUsersToReceiveEmailNotifications',
      },
      'start'
    );
});

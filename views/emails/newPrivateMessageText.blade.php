{{ $translator->trans('neoncube-private-messages.forum.notifications.email.new_private_message.youHaveReceivedNewMessage', ['{user}' => $blueprint->user->display_name]) }}

{{ $translator->trans('neoncube-private-messages.forum.notifications.email.new_private_message.viewMessage') }}:
{{ $blueprint->getViewMessageUrl($url) }}

{{ $translator->trans('neoncube-private-messages.forum.notifications.email.new_private_message.manageEmailSettings') }}:
{{ $url->to('forum')->route('settings') }}

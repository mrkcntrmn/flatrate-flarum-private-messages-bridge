{{ $translator->trans('neoncube-private-messages.forum.notifications.email.new_private_message.youHaveReceivedNewMessage', ['{user}' => $blueprint->user->display_name]) }}

<hr style="margin-top: 1em; margin-bottom: 2em" />

<a href="{{$url->to('forum')->route('neoncube-private-messages.messages', ['id' => $blueprint->message->conversation_id, 'number' => $blueprint->message->number])}}" target="_blank" style="padding: 0.5em 1em; background: {{$blueprint->primaryColor}}; color: {{$blueprint->secondaryColor}};">{{$translator->trans('neoncube-private-messages.forum.notifications.email.new_private_message.viewMessage')}}</a>

<hr style="margin-top: 2em; margin-bottom: 1em" />

<a href="{{$url->to('forum')->route('settings')}}" target="_blank">{{$translator->trans('neoncube-private-messages.forum.notifications.email.new_private_message.manageEmailSettings')}}</a>

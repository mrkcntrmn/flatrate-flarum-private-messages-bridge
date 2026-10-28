<?php

namespace Neoncube\FlarumPrivateMessages\Notifications;

use Flarum\Database\AbstractModel;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Neoncube\FlarumPrivateMessages\Message;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shared DM notification blueprint body.
 *
 * Flarum 2 requires BlueprintInterface return types and AlertableInterface for
 * in-app alerts. Flarum 1 has no AlertableInterface, so the concrete class is
 * selected below after this abstract body is defined.
 */
abstract class NewPrivateMessageBlueprintBody implements BlueprintInterface, MailableInterface
{
    public $message;
    public $conversation;
    public $user;
    public $primaryColor;
    public $secondaryColor;

    public function __construct(Message $message, $conversation, $user, SettingsRepositoryInterface $settings)
    {
        $this->message = $message;
        $this->conversation = $conversation;
        $this->user = $user;
        $this->primaryColor = $settings->get('theme_primary_color');
        $this->secondaryColor = $settings->get('theme_secondary_color');
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->message;
    }

    public function getFromUser(): ?User
    {
        return $this->user instanceof User ? $this->user : null;
    }

    public function getData(): mixed
    {
        $conversationId = null;
        if (is_object($this->conversation) && isset($this->conversation->id)) {
            $conversationId = $this->conversation->id;
        } elseif (is_numeric($this->conversation)) {
            $conversationId = $this->conversation;
        }

        return [
            'messageId' => $this->message->id ?? null,
            'conversationId' => $conversationId,
            'fromUserId' => ($this->user instanceof User) ? ($this->user->id ?? null) : null,
        ];
    }

    public static function getType(): string
    {
        return 'newPrivateMessage';
    }

    public static function getSubjectModel(): string
    {
        return Message::class;
    }

    /**
     * Flarum 1 MailableInterface contract.
     *
     * @return array{html: string}
     */
    public function getEmailView()
    {
        return [
            'html' => 'flarum-private-messages::emails.newPrivateMessageHtml'
        ];
    }

    /**
     * Flarum 2 MailableInterface contract.
     *
     * @return array{text: string, html: string}
     */
    public function getEmailViews(): array
    {
        $view = $this->getEmailView();
        $html = is_array($view) ? (string) ($view['html'] ?? '') : (string) $view;

        return [
            'text' => $html,
            'html' => $html,
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('neoncube-private-messages.forum.notifications.email.new_private_message.subject', [
             '{user}' => $this->user->display_name
        ]);
    }
}

if (interface_exists(\Flarum\Notification\AlertableInterface::class)) {
    class NewPrivateMessageBlueprint extends NewPrivateMessageBlueprintBody implements \Flarum\Notification\AlertableInterface
    {
    }
} else {
    class NewPrivateMessageBlueprint extends NewPrivateMessageBlueprintBody
    {
    }
}

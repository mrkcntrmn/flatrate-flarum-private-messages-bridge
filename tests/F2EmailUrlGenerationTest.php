<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Neoncube\FlarumPrivateMessages\Conversation;
use Neoncube\FlarumPrivateMessages\Message;
use Neoncube\FlarumPrivateMessages\Notifications\NewPrivateMessageBlueprint;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Guards F2 email URL generation without invoking the missing named forum route.
 */
class F2EmailUrlGenerationTest extends TestCase
{
    public function testGetViewMessageUrlUsesForumPathOnFlarum2(): void
    {
        if (!$this->isFlarum2Core()) {
            $this->markTestSkipped('Flarum 2.0.2 core is required.');
        }

        $sender = new User();
        $sender->id = 42;
        $sender->username = 'alice';

        $message = new Message();
        $message->id = 7;
        $message->conversation_id = 99;
        $message->number = 1;

        $conversation = new Conversation();
        $conversation->id = 99;

        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturn('#111111');

        $blueprint = new NewPrivateMessageBlueprint($message, $conversation, $sender, $settings);

        $forum = new class {
            public function path(string $path): string
            {
                return 'http://forum.test/' . ltrim($path, '/');
            }

            public function route(string $name, array $parameters = []): string
            {
                throw new \RuntimeException('route() must not be used for F2 messaging email URLs: ' . $name);
            }
        };

        $url = new class ($forum) {
            public function __construct(private $forum)
            {
            }

            public function to(string $name)
            {
                if ($name !== 'forum') {
                    throw new \InvalidArgumentException($name);
                }

                return $this->forum;
            }
        };

        $generated = $blueprint->getViewMessageUrl($url);
        $this->assertSame('http://forum.test/messages/direct/99', $generated);

        $views = $blueprint->getEmailViews();
        $this->assertSame('flarum-private-messages::emails.newPrivateMessageHtml', $views['html']);
        $this->assertSame('flarum-private-messages::emails.newPrivateMessageText', $views['text']);
    }

    private function isFlarum2Core(): bool
    {
        if (!interface_exists(BlueprintInterface::class)) {
            return false;
        }

        $method = (new ReflectionClass(BlueprintInterface::class))->getMethod('getFromUser');
        $type = $method->getReturnType();

        return $type instanceof ReflectionNamedType
            && $type->allowsNull()
            && $type->getName() === User::class;
    }
}

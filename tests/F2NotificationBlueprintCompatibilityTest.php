<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use Flarum\Database\AbstractModel;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Neoncube\FlarumPrivateMessages\Conversation;
use Neoncube\FlarumPrivateMessages\Message;
use Neoncube\FlarumPrivateMessages\Notifications\NewPrivateMessageBlueprint;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Guards the Flarum 2.0.2 BlueprintInterface / MailableInterface contracts.
 *
 * Production EXEC-002 failed because NewPrivateMessageBlueprint::getFromUser()
 * lacked the required ?User return type. php -l cannot catch that; the class
 * must load alongside the real F2 interface.
 */
class F2NotificationBlueprintCompatibilityTest extends TestCase
{
    public function testBlueprintClassLoadsAgainstF2Interfaces(): void
    {
        if (!$this->isFlarum2Core()) {
            $this->markTestSkipped('Flarum 2.0.2 core is required for blueprint interface loading.');
        }

        $this->assertTrue(
            class_exists(NewPrivateMessageBlueprint::class),
            'NewPrivateMessageBlueprint must load without a PHP declaration fatal'
        );

        $ref = new ReflectionClass(NewPrivateMessageBlueprint::class);
        $this->assertTrue($ref->implementsInterface(BlueprintInterface::class));
        $this->assertTrue($ref->implementsInterface(MailableInterface::class));
        $this->assertTrue(
            $ref->implementsInterface(\Flarum\Notification\AlertableInterface::class),
            'F2 alert driver requires AlertableInterface'
        );
    }

    public function testBlueprintMethodsMatchF2Contracts(): void
    {
        if (!$this->isFlarum2Core()) {
            $this->markTestSkipped('Flarum 2.0.2 core is required for blueprint signature assertions.');
        }

        $ref = new ReflectionClass(NewPrivateMessageBlueprint::class);

        $this->assertReturnType($ref, 'getFromUser', User::class, true);
        $this->assertReturnType($ref, 'getSubject', AbstractModel::class, true);
        $this->assertReturnTypeAllows($ref, 'getData', ['mixed']);
        $this->assertReturnType($ref, 'getType', 'string', false);
        $this->assertReturnType($ref, 'getSubjectModel', 'string', false);
        $this->assertReturnType($ref, 'getEmailViews', 'array', false);
        $this->assertReturnType($ref, 'getEmailSubject', 'string', false);

        // F1 MailableInterface method must remain available.
        $this->assertTrue($ref->hasMethod('getEmailView'));
    }

    public function testGetFromUserReturnsSenderIdentity(): void
    {
        if (!$this->isFlarum2Core()) {
            $this->markTestSkipped('Flarum 2.0.2 core is required for blueprint instantiation.');
        }

        $sender = new User();
        $sender->id = 42;
        $sender->username = 'alice';

        $message = new Message();
        $message->id = 7;
        $message->user_id = 42;

        $conversation = new Conversation();
        $conversation->id = 3;

        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnMap([
            ['theme_primary_color', null, '#111111'],
            ['theme_secondary_color', null, '#222222'],
        ]);

        $blueprint = new NewPrivateMessageBlueprint($message, $conversation, $sender, $settings);

        $from = $blueprint->getFromUser();
        $this->assertInstanceOf(User::class, $from);
        $this->assertSame(42, (int) $from->id);
        $this->assertSame($message, $blueprint->getSubject());
        $this->assertSame('newPrivateMessage', NewPrivateMessageBlueprint::getType());
        $this->assertSame(Message::class, NewPrivateMessageBlueprint::getSubjectModel());

        $views = $blueprint->getEmailViews();
        $this->assertArrayHasKey('html', $views);
        $this->assertArrayHasKey('text', $views);
    }

    public function testGetFromUserReturnsNullForNonUserSender(): void
    {
        if (!$this->isFlarum2Core()) {
            $this->markTestSkipped('Flarum 2.0.2 core is required for nullable sender case.');
        }

        $message = new Message();
        $conversation = new Conversation();
        $settings = $this->createMock(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturn(null);

        $blueprint = new NewPrivateMessageBlueprint($message, $conversation, null, $settings);
        $this->assertNull($blueprint->getFromUser());
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

    private function assertReturnType(
        ReflectionClass $ref,
        string $method,
        string $expected,
        bool $nullable
    ): void {
        $type = $ref->getMethod($method)->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type, $method.' must declare a named return type');
        $this->assertSame($expected, $type->getName(), $method.' return type');
        $this->assertSame($nullable, $type->allowsNull(), $method.' nullability');
    }

    /**
     * @param list<string> $allowed
     */
    private function assertReturnTypeAllows(ReflectionClass $ref, string $method, array $allowed): void
    {
        $type = $ref->getMethod($method)->getReturnType();
        $this->assertNotNull($type, $method.' must declare a return type');

        if ($type instanceof ReflectionNamedType) {
            $this->assertContains($type->getName(), $allowed, $method.' return type');
            return;
        }

        if ($type instanceof ReflectionUnionType) {
            $names = array_map(
                static fn ($part) => $part instanceof ReflectionNamedType ? $part->getName() : (string) $part,
                $type->getTypes()
            );
            foreach ($allowed as $name) {
                if (in_array($name, $names, true) || $name === 'mixed') {
                    $this->assertTrue(true);
                    return;
                }
            }
        }

        $this->assertSame('mixed', (string) $type, $method.' return type');
    }
}

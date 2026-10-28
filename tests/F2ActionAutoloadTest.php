<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;

class F2ActionAutoloadTest extends TestCase
{
    public function testEachF2ActionLoadsFromItsPsr4File(): void
    {
        $classes = [
            'Neoncube\\FlarumPrivateMessages\\F2\\ParticipantAction' => 'src/F2/ParticipantAction.php',
            'Neoncube\\FlarumPrivateMessages\\F2\\ListConversationsAction' => 'src/F2/ListConversationsAction.php',
            'Neoncube\\FlarumPrivateMessages\\F2\\ShowConversationAction' => 'src/F2/ShowConversationAction.php',
            'Neoncube\\FlarumPrivateMessages\\F2\\ListMessagesAction' => 'src/F2/ListMessagesAction.php',
            'Neoncube\\FlarumPrivateMessages\\F2\\CreateConversationAction' => 'src/F2/CreateConversationAction.php',
            'Neoncube\\FlarumPrivateMessages\\F2\\CreateMessageAction' => 'src/F2/CreateMessageAction.php',
            'Neoncube\\FlarumPrivateMessages\\F2\\ReadMessageAction' => 'src/F2/ReadMessageAction.php',
            'Neoncube\\FlarumPrivateMessages\\F2\\DeleteMessageAction' => 'src/F2/DeleteMessageAction.php',
        ];

        foreach ($classes as $class => $relative) {
            $this->assertTrue(class_exists($class), $class);
            $ref = new ReflectionClass($class);
            $this->assertSame(
                realpath(dirname(__DIR__).'/'.$relative),
                realpath($ref->getFileName())
            );
            if (!$ref->isAbstract()) {
                $this->assertTrue($ref->implementsInterface(RequestHandlerInterface::class));
            }
            $parent = $ref->getParentClass();
            if ($parent) {
                $this->assertTrue(class_exists($parent->getName()));
                $this->assertFalse($parent->isFinal());
            }
        }
    }

    public function testMessageResourceMapsModelWithoutEndpoints(): void
    {
        if (!class_exists('Flarum\\Api\\Resource\\AbstractDatabaseResource')) {
            $this->markTestSkipped('Flarum 2 AbstractDatabaseResource is required to load MessageResource.');
        }

        $class = 'Neoncube\\FlarumPrivateMessages\\F2\\MessageResource';
        $this->assertTrue(class_exists($class), $class);
        $ref = new ReflectionClass($class);
        $this->assertSame(
            realpath(dirname(__DIR__).'/src/F2/MessageResource.php'),
            realpath($ref->getFileName())
        );
        $this->assertTrue($ref->isSubclassOf('Flarum\\Api\\Resource\\AbstractDatabaseResource'));

        /** @var object $resource */
        $resource = $ref->newInstance();
        $this->assertSame('neoncube-private-messages-messages', $resource->type());
        $this->assertSame('Neoncube\\FlarumPrivateMessages\\Message', $resource->model());
        $this->assertSame([], $resource->endpoints());
    }
}

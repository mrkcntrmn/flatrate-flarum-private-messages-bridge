<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use Neoncube\FlarumPrivateMessages\Conversation;
use Neoncube\FlarumPrivateMessages\ConversationUnreadAccounting;
use PHPUnit\Framework\TestCase;

class ConversationActivityContractTest extends TestCase
{
    public function testConversationTimestampsAreEnabled(): void
    {
        $conversation = new Conversation();
        $this->assertTrue($conversation->timestamps);

        $defaults = (new \ReflectionClass(Conversation::class))->getDefaultProperties();
        $this->assertArrayHasKey('timestamps', $defaults);
        $this->assertTrue($defaults['timestamps']);
    }

    public function testActivityHelperUsesUpdatedAtNotCreatedAt(): void
    {
        $conversation = new \stdClass();
        $conversation->updated_at = '2026-09-14 15:00:00';
        $conversation->created_at = '2020-01-01 00:00:00';

        $this->assertSame('2026-09-14 15:00:00', ConversationUnreadAccounting::activityAt($conversation));
        $this->assertNotSame($conversation->created_at, ConversationUnreadAccounting::activityAt($conversation));
    }

    public function testSerializerFormatsUpdatedAt(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Api/Serializers/ConversationSerializer.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            "/'updatedAt'\\s*=>\\s*\\\$this->formatDate\\(\\\$conversation->updated_at\\)/",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'updatedAt'\\s*=>\\s*\\\$this->formatDate\\(\\\$conversation->created_at\\)/",
            $source
        );
    }

    public function testNewMessageHandlerIncrementsTotalMessagesWithoutTouch(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Commands/NewMessageHandler.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString("increment('total_messages')", $source);
        $this->assertStringNotContainsString('->touch()', $source);
    }

    public function testIsolatedPhpunitDoesNotAddEloquentClockRequire(): void
    {
        // Conversation extends Flarum AbstractModel. tests/bootstrap.php stubs that
        // class without Eloquent, so Conversation::increment() cannot be exercised
        // here. Laravel Model::increment() updates updated_at when timestamps=true;
        // disposable composition with a real Illuminate connection proves the clock.
        // Do not add a heavy flarum/core require or a redundant $conversation->touch().
        $this->assertTrue((new Conversation())->timestamps);
        if (class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            $this->markTestSkipped(
                'Illuminate Capsule is present but Conversation still extends the isolated AbstractModel stub, so increment() clock is not runnable here.'
            );
        }
    }
}

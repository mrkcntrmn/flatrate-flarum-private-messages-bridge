<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use Neoncube\FlarumPrivateMessages\F2\ConversationVisibility;
use PHPUnit\Framework\TestCase;

class ConversationVisibilityTest extends TestCase
{
    public function testOnlyParticipantIdsAreVisible(): void
    {
        $rows = [
            ['id' => 10, 'participants' => [1, 2]],
            ['id' => 11, 'participants' => [2, 3]],
            ['id' => 12, 'participants' => [4]],
        ];

        $this->assertSame([], ConversationVisibility::visibleIds(null, $rows));
        $this->assertSame([10], ConversationVisibility::visibleIds(1, $rows));
        $this->assertSame([11], ConversationVisibility::visibleIds(3, $rows));
        $this->assertSame([], ConversationVisibility::visibleIds(9, $rows));
    }

    public function testF2EntryRegistersAlertAndEmailNotificationsOmitsTypingAndClientUsername(): void
    {
        $extend = (string) file_get_contents(dirname(__DIR__).'/extend.php');
        $f2 = (string) file_get_contents(dirname(__DIR__).'/extend-f2.php');
        $actions = '';
        foreach (glob(dirname(__DIR__).'/src/F2/*.php') as $file) {
            $actions .= (string) file_get_contents($file);
        }
        $this->assertFileDoesNotExist(dirname(__DIR__).'/src/F2/ParticipantActions.php');
        $this->assertStringContainsString('$message->message', $actions);
        $this->assertStringNotContainsString('$message->content', $actions);

        $this->assertStringContainsString('AbstractShowController::class', $extend);
        $this->assertStringContainsString('extend-f2.php', $extend);
        $this->assertStringContainsString('Extend\\Notification', $f2);
        $this->assertStringContainsString("->type(NewPrivateMessageBlueprint::class, ['alert', 'email'])", $f2);
        $this->assertStringNotContainsString('/messages/typing', $f2);
        $this->assertStringContainsString("js(__DIR__.'/js/dist/forum-f2.js')", $f2);
        $this->assertStringContainsString('forum-f2.js', $f2);
        $this->assertFileExists(dirname(__DIR__).'/js/dist/forum-f2.js');
        $this->assertStringContainsString("new Extend\\Locales(__DIR__.'/resources/locale')", $f2);
        $this->assertStringContainsString("(new Extend\\Routes('api'))", $f2);
        $this->assertStringContainsString("where('user_id', \$actor->id)", $actions);
        $this->assertStringContainsString('assertParticipantInConversationId', $actions);
        $this->assertStringNotContainsString('body[\'username\']', $actions);
        $this->assertStringContainsString('RequestUtil::getActor($request)', $actions);
        $this->assertStringNotContainsString("getAttribute('actor')", $actions);
    }
}

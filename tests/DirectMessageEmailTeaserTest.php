<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use PHPUnit\Framework\TestCase;

class DirectMessageEmailTeaserTest extends TestCase
{
    public function testHtmlEmailViewDoesNotRenderMessageBody(): void
    {
        $path = dirname(__DIR__) . '/views/emails/newPrivateMessageHtml.blade.php';
        $this->assertFileExists($path);
        $contents = file_get_contents($path);

        $this->assertStringNotContainsString('message->message', $contents);
        $this->assertStringNotContainsString('{content}', $contents);
        $this->assertStringContainsString('youHaveReceivedNewMessage', $contents);
        $this->assertStringContainsString('viewMessage', $contents);
    }

    public function testEnglishEmailCopyIsTeaserOnly(): void
    {
        $path = dirname(__DIR__) . '/resources/locale/en.yml';
        $contents = file_get_contents($path);

        $this->assertStringContainsString('sent you a direct message on FlatRate.wiki.', $contents);
        $this->assertStringContainsString('View Direct Message', $contents);
        $this->assertStringNotContainsString('{content}', $contents);
    }
}

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
        $this->assertStringContainsString('getViewMessageUrl', $contents);
        $this->assertStringNotContainsString("route('neoncube-private-messages.messages'", $contents);
    }

    public function testTextEmailViewDoesNotRenderMessageBody(): void
    {
        $path = dirname(__DIR__) . '/views/emails/newPrivateMessageText.blade.php';
        $this->assertFileExists($path);
        $contents = file_get_contents($path);

        $this->assertStringNotContainsString('message->message', $contents);
        $this->assertStringNotContainsString('{content}', $contents);
        $this->assertStringContainsString('youHaveReceivedNewMessage', $contents);
        $this->assertStringContainsString('getViewMessageUrl', $contents);
        $this->assertStringNotContainsString("route('neoncube-private-messages.messages'", $contents);
    }

    public function testEnglishEmailCopyIsTeaserOnly(): void
    {
        $path = dirname(__DIR__) . '/resources/locale/en.yml';
        $contents = file_get_contents($path);

        $this->assertStringContainsString('sent you a direct message on FlatRate.wiki.', $contents);
        $this->assertStringContainsString('View Direct Message', $contents);
        $this->assertStringNotContainsString('{content}', $contents);
    }

    public function testNoEmailTemplateUsesMissingF2ForumRoute(): void
    {
        $dir = dirname(__DIR__) . '/views/emails';
        foreach (glob($dir . '/*') as $file) {
            $contents = file_get_contents($file);
            $this->assertStringNotContainsString(
                "route('neoncube-private-messages.messages'",
                $contents,
                basename($file) . ' must not call the F1-only forum route name'
            );
        }
    }
}

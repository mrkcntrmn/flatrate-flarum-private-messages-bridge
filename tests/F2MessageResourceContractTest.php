<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contract checks that do not boot Flarum: the resource must stay endpoint-free
 * and keep the unique type used by notification subject resolution.
 */
class F2MessageResourceContractTest extends TestCase
{
    public function testExtendF2RegistersMessageResource(): void
    {
        $extend = file_get_contents(dirname(__DIR__).'/extend-f2.php');
        $this->assertNotFalse($extend);
        $this->assertStringContainsString(
            'new Extend\\ApiResource(MessageResource::class)',
            $extend
        );
        $this->assertStringContainsString(
            'use Neoncube\\FlarumPrivateMessages\\F2\\MessageResource;',
            $extend
        );
    }

    public function testF1MessageSerializerTypeUnchanged(): void
    {
        $serializer = file_get_contents(
            dirname(__DIR__).'/src/Api/Serializers/MessageSerializer.php'
        );
        $this->assertNotFalse($serializer);
        $this->assertMatchesRegularExpression(
            '/protected \$type = \'messages\';/',
            $serializer
        );
    }

    public function testMessageResourceSourceHasEmptyEndpoints(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/src/F2/MessageResource.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString("return 'neoncube-private-messages-messages';", $source);
        $this->assertMatchesRegularExpression(
            '/function endpoints\(\):\s*array\s*\{\s*return \[\];\s*\}/s',
            $source
        );
    }
}

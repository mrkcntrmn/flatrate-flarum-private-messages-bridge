<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use Flarum\User\User;
use InvalidArgumentException;
use Neoncube\FlarumPrivateMessages\ConversationValidator;
use PHPUnit\Framework\TestCase;

class ConversationValidatorTest extends TestCase
{
    private function actor(int $id = 7): User
    {
        return new User($id);
    }

    private function validator(array $existingUsers = [12 => true]): ConversationValidator
    {
        return new ConversationValidator(function ($id) use ($existingUsers) {
            return !empty($existingUsers[$id]) ? new User($id) : null;
        });
    }

    public function testAcceptsScalarRecipient(): void
    {
        $id = $this->validator()->normalizeRecipientId(12, $this->actor());
        $this->assertSame(12, $id);

        $id = $this->validator()->normalizeRecipientId('12', $this->actor());
        $this->assertSame(12, $id);
    }

    /**
     * @dataProvider malformedRecipients
     */
    public function testRejectsMalformedRecipients($recipient): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator()->normalizeRecipientId($recipient, $this->actor());
    }

    public function malformedRecipients(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace' => ['  '],
            'empty array' => [[]],
            'array of one' => [[12]],
            'array of two' => [[12, 34]],
            'object' => [(object) ['id' => 12]],
            'true' => [true],
            'false' => [false],
            'float' => [12.5],
            'non-digit string' => ['abc'],
        ];
    }

    public function testRejectsSelfRecipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator([7 => true])->normalizeRecipientId(7, $this->actor(7));
    }

    public function testRejectsNonexistentRecipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator([])->normalizeRecipientId(99, $this->actor());
    }

    public function testMessageContentsValidation(): void
    {
        $v = $this->validator();
        $this->assertSame('hello', $v->normalizeMessageContents('hello'));
        $this->assertSame("  hi\n", $v->normalizeMessageContents("  hi\n"));

        foreach ([null, 1, true, [], new \stdClass(), '', '   ', "\n\t"] as $bad) {
            try {
                $v->normalizeMessageContents($bad);
                $this->fail('Expected InvalidArgumentException for ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testMessageContentsFromData(): void
    {
        $v = $this->validator();
        $this->assertSame(
            'ping',
            $v->messageContentsFromData(['attributes' => ['messageContents' => 'ping']])
        );
    }
}

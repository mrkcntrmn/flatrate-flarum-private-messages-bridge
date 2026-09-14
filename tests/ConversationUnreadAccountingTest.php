<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use Flarum\User\User;
use Neoncube\FlarumPrivateMessages\ConversationUnreadAccounting;
use PHPUnit\Framework\TestCase;

class ConversationUnreadAccountingTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const C = 3;

    public function testSequenceAThenBBUnreadForAIsTwoAndBIsZero(): void
    {
        // A sends m1, B sends m2, B sends m3, A lastRead=m1 => A unread=2, B unread=0.
        $messages = [
            ['number' => 1, 'user_id' => self::A],
            ['number' => 2, 'user_id' => self::B],
            ['number' => 3, 'user_id' => self::B],
        ];

        $this->assertSame(2, ConversationUnreadAccounting::countUnreadIncoming($messages, 1, self::A));
        $this->assertSame(0, ConversationUnreadAccounting::countUnreadIncoming($messages, 3, self::B));

        $queryA = new RecordingMessageQuery($this->withConversation($messages, 10));
        $this->assertSame(2, ConversationUnreadAccounting::unreadIncomingCount($queryA, 1, self::A));
        $this->assertSame(
            [
                ['number', '>', 1],
                ['user_id', '!=', self::A],
            ],
            $queryA->wheres
        );
        $this->assertTrue($queryA->counted);
    }

    public function testAfterAReadsM3UnreadIsZero(): void
    {
        $messages = [
            ['number' => 1, 'user_id' => self::A],
            ['number' => 2, 'user_id' => self::B],
            ['number' => 3, 'user_id' => self::B],
        ];

        $this->assertSame(0, ConversationUnreadAccounting::countUnreadIncoming($messages, 3, self::A));
        $this->assertSame(
            0,
            ConversationUnreadAccounting::unreadIncomingCount(
                new RecordingMessageQuery($this->withConversation($messages, 10)),
                3,
                self::A
            )
        );
    }

    public function testOwnSentMessagesDoNotInflateUnread(): void
    {
        // A sends m1, A sends m2, B sends m3 => A unread=1 (not 3).
        $messages = [
            ['number' => 1, 'user_id' => self::A, 'is_seen' => false],
            ['number' => 2, 'user_id' => self::A, 'is_seen' => false],
            ['number' => 3, 'user_id' => self::B, 'is_seen' => false],
        ];

        $this->assertSame(1, ConversationUnreadAccounting::countUnreadIncoming($messages, 0, self::A));
        $this->assertNotSame(3, ConversationUnreadAccounting::countUnreadIncoming($messages, 0, self::A));
        $this->assertSame(
            1,
            ConversationUnreadAccounting::unreadCountForActor(
                $this->conversationFixture([self::A => 0, self::B => 0], $this->withConversation($messages, 10)),
                new User(self::A)
            )
        );
    }

    public function testGuestUnreadIsZero(): void
    {
        $conversation = $this->conversationFixture(
            [],
            $this->withConversation(
                [['number' => 1, 'user_id' => self::B]],
                10
            )
        );

        $this->assertSame(0, ConversationUnreadAccounting::unreadCountForActor($conversation, new User(null)));
        $this->assertSame(0, ConversationUnreadAccounting::unreadCountForActor($conversation, null));
    }

    public function testConsumedIncomingIgnoresOwnMessagesAndAlreadyRead(): void
    {
        $messages = $this->withConversation(
            [
                ['number' => 1, 'user_id' => self::A],
                ['number' => 2, 'user_id' => self::B],
                ['number' => 3, 'user_id' => self::B],
            ],
            10
        );

        $this->assertSame(2, ConversationUnreadAccounting::countConsumedIncoming($messages, 1, 3, self::A));
        $this->assertSame(0, ConversationUnreadAccounting::countConsumedIncoming($messages, 3, 3, self::A));
        $this->assertSame(0, ConversationUnreadAccounting::countConsumedIncoming($messages, 3, 2, self::A));

        $query = new RecordingMessageQuery($messages);
        $consumed = ConversationUnreadAccounting::consumedIncoming(10, self::A, 1, 3, $query);
        $this->assertSame(2, $consumed);
        $this->assertSame(
            [
                ['conversation_id', '=', 10],
                ['number', '>', 1],
                ['number', '<=', 3],
                ['user_id', '!=', self::A],
            ],
            $query->wheres
        );

        $already = new RecordingMessageQuery($messages);
        $this->assertSame(0, ConversationUnreadAccounting::consumedIncoming(10, self::A, 3, 2, $already));
        $this->assertSame([], $already->wheres);
        $this->assertFalse($already->counted);
    }

    public function testMultiConversationGlobalUnreadDecrement(): void
    {
        // AB unread for A = 1, AC unread for A = 2, global A unread = 3.
        // A reads AB => AB = 0, AC = 2, global = 2.
        $ab = $this->withConversation(
            [
                ['number' => 1, 'user_id' => self::B],
            ],
            11
        );
        $ac = $this->withConversation(
            [
                ['number' => 1, 'user_id' => self::C],
                ['number' => 2, 'user_id' => self::C],
            ],
            12
        );

        $abUnread = ConversationUnreadAccounting::countUnreadIncoming($ab, 0, self::A);
        $acUnread = ConversationUnreadAccounting::countUnreadIncoming($ac, 0, self::A);
        $global = $abUnread + $acUnread;

        $this->assertSame(1, $abUnread);
        $this->assertSame(2, $acUnread);
        $this->assertSame(3, $global);

        $consumed = ConversationUnreadAccounting::consumedIncoming(
            11,
            self::A,
            0,
            1,
            new RecordingMessageQuery($ab)
        );
        $this->assertSame(1, $consumed);

        $globalAfter = $global - $consumed;
        $this->assertSame(0, ConversationUnreadAccounting::countUnreadIncoming($ab, 1, self::A));
        $this->assertSame(2, ConversationUnreadAccounting::countUnreadIncoming($ac, 0, self::A));
        $this->assertSame(2, $globalAfter);
    }

    public function testReadMessageHandlerUsesConsumedIncomingNotSequenceGap(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Commands/ReadMessageHandler.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('ConversationUnreadAccounting::consumedIncoming', $source);
        $this->assertStringNotContainsString("decrement('unread_messages', \$number - \$oldRead)", $source);
        $this->assertStringContainsString("if (\$consumedUnread > 0)", $source);
    }

    public function testSerializerDoesNotUseIsSeen(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Api/Serializers/ConversationSerializer.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('unreadCountForActor', $source);
        $this->assertStringNotContainsString('is_seen', $source);
        $this->assertStringNotContainsString('->get()', $source);
    }

    /**
     * @param array<int, array{number:int, user_id:int}> $messages
     * @return array<int, array{number:int, user_id:int, conversation_id:int}>
     */
    private function withConversation(array $messages, int $conversationId): array
    {
        return array_map(static function (array $message) use ($conversationId) {
            $message['conversation_id'] = $conversationId;
            return $message;
        }, $messages);
    }

    /**
     * @param array<int, int> $lastReadByUser
     * @param array<int, array<string, mixed>> $messages
     */
    private function conversationFixture(array $lastReadByUser, array $messages): object
    {
        return new class($lastReadByUser, $messages) {
            private $lastReadByUser;
            private $messages;

            public function __construct(array $lastReadByUser, array $messages)
            {
                $this->lastReadByUser = $lastReadByUser;
                $this->messages = $messages;
            }

            public function recipients()
            {
                $map = $this->lastReadByUser;
                return new class($map) {
                    private $map;
                    private $userId;

                    public function __construct(array $map)
                    {
                        $this->map = $map;
                    }

                    public function where($column, $value)
                    {
                        $this->userId = (int) $value;
                        return $this;
                    }

                    public function first()
                    {
                        if (!array_key_exists($this->userId, $this->map)) {
                            return null;
                        }
                        $row = new \stdClass();
                        $row->user_id = $this->userId;
                        $row->last_read_message_number = $this->map[$this->userId];
                        return $row;
                    }
                };
            }

            public function messages()
            {
                return new RecordingMessageQuery($this->messages);
            }
        };
    }
}

/**
 * Eloquent-like query fake: records where-clauses and counts in-memory rows.
 */
class RecordingMessageQuery
{
    /** @var array<int, array{0:string,1:string,2:mixed}> */
    public array $wheres = [];
    public bool $counted = false;
    /** @var array<int, array<string, mixed>> */
    private array $rows;

    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    public function where($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        $this->wheres[] = [(string) $column, (string) $operator, $value];
        return $this;
    }

    public function count(): int
    {
        $this->counted = true;
        $n = 0;
        foreach ($this->rows as $row) {
            if ($this->rowMatches($row)) {
                $n++;
            }
        }
        return $n;
    }

    private function rowMatches(array $row): bool
    {
        foreach ($this->wheres as [$column, $operator, $value]) {
            $actual = $row[$column] ?? null;
            switch ($operator) {
                case '>':
                    if ((int) $actual <= (int) $value) {
                        return false;
                    }
                    break;
                case '>=':
                    if ((int) $actual < (int) $value) {
                        return false;
                    }
                    break;
                case '<':
                    if ((int) $actual >= (int) $value) {
                        return false;
                    }
                    break;
                case '<=':
                    if ((int) $actual > (int) $value) {
                        return false;
                    }
                    break;
                case '!=':
                case '<>':
                    if ((int) $actual === (int) $value) {
                        return false;
                    }
                    break;
                case '=':
                    if ((string) $actual !== (string) $value) {
                        return false;
                    }
                    break;
                default:
                    return false;
            }
        }
        return true;
    }
}

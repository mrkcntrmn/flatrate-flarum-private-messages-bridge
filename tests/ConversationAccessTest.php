<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Neoncube\FlarumPrivateMessages\Conversation;
use Neoncube\FlarumPrivateMessages\ConversationAccess;
use PHPUnit\Framework\TestCase;

class ConversationAccessTest extends TestCase
{
    public function testAssertParticipantDeniesGuest(): void
    {
        $access = new ConversationAccess();
        $guest = new User(null);
        $conversation = $this->conversationWithMembership([]);

        $this->expectException(PermissionDeniedException::class);
        $access->assertParticipant($guest, $conversation);
    }

    public function testAssertParticipantAllowsMember(): void
    {
        $access = new ConversationAccess();
        $actor = new User(1);
        $conversation = $this->conversationWithMembership([1, 2]);

        $access->assertParticipant($actor, $conversation);
        $this->addToAssertionCount(1);
    }

    public function testAssertParticipantDeniesNonMember(): void
    {
        $access = new ConversationAccess();
        $actor = new User(3);
        $conversation = $this->conversationWithMembership([1, 2]);

        // Document why get()-truthiness was unsafe: empty objects are truthy.
        $emptyObject = new \stdClass();
        $this->assertTrue((bool) $emptyObject);

        $this->expectException(PermissionDeniedException::class);
        $access->assertParticipant($actor, $conversation);
    }

    public function testRequireOneToOnePeerDerivesOtherParticipant(): void
    {
        $peerUser = new User(2);
        $access = new ConversationAccess(function ($id) use ($peerUser) {
            return (int) $id === 2 ? $peerUser : null;
        });
        $actor = new User(1);
        $conversation = $this->conversationWithMembership([1, 2]);

        $peer = $access->requireOneToOnePeer($actor, $conversation);
        $this->assertSame(2, (int) $peer->id);
    }

    public function testRequireOneToOnePeerRejectsMalformedGroup(): void
    {
        $access = new ConversationAccess(function ($id) {
            return new User($id);
        });
        $actor = new User(1);
        $conversation = $this->conversationWithMembership([1, 2, 3]);

        $this->expectException(PermissionDeniedException::class);
        $access->requireOneToOnePeer($actor, $conversation);
    }

    public function testClientSuppliedDestinationMustNotBypassPeerDerivation(): void
    {
        $peerUser = new User(2);
        $attackerChosen = new User(999);
        $access = new ConversationAccess(function ($id) use ($peerUser, $attackerChosen) {
            if ((int) $id === 2) {
                return $peerUser;
            }
            if ((int) $id === 999) {
                return $attackerChosen;
            }
            return null;
        });

        $actor = new User(1);
        $conversation = $this->conversationWithMembership([1, 2]);

        // Even if a client posts userId=999, peer must be derived as 2.
        $peer = $access->requireOneToOnePeer($actor, $conversation);
        $this->assertSame(2, (int) $peer->id);
        $this->assertNotSame(999, (int) $peer->id);
    }

    /**
     * @param int[] $memberIds
     */
    private function conversationWithMembership(array $memberIds): Conversation
    {
        $conversation = $this->getMockBuilder(Conversation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['recipients'])
            ->getMock();

        $conversation->method('recipients')->willReturn(new class($memberIds) {
            private $memberIds;
            private $filterId;

            public function __construct(array $memberIds)
            {
                $this->memberIds = array_map('intval', $memberIds);
            }

            public function where($column, $value)
            {
                $this->filterId = (int) $value;
                return $this;
            }

            public function exists(): bool
            {
                return in_array($this->filterId, $this->memberIds, true);
            }

            public function pluck($column)
            {
                $ids = $this->memberIds;
                return new class($ids) {
                    private $ids;
                    public function __construct(array $ids)
                    {
                        $this->ids = $ids;
                    }
                    public function map(callable $cb)
                    {
                        $this->ids = array_map($cb, $this->ids);
                        return $this;
                    }
                    public function unique()
                    {
                        $this->ids = array_values(array_unique($this->ids));
                        return $this;
                    }
                    public function values()
                    {
                        $this->ids = array_values($this->ids);
                        return $this;
                    }
                    public function all(): array
                    {
                        return $this->ids;
                    }
                };
            }
        });

        return $conversation;
    }
}

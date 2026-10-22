<?php

namespace Neoncube\FlarumPrivateMessages\F2;

/**
 * Participant filter used by the Flarum 2 list route.
 * An authenticated user is not a participant.
 */
final class ConversationVisibility
{
    /**
     * @param list<array{id:int|string,participants:list<int>}> $conversations
     * @return list<int>
     */
    public static function visibleIds(?int $actorId, array $conversations): array
    {
        if ($actorId === null || $actorId === 0) {
            return [];
        }

        $visible = [];
        foreach ($conversations as $conversation) {
            $participants = array_map('intval', $conversation['participants'] ?? []);
            if (in_array($actorId, $participants, true)) {
                $visible[] = (int) $conversation['id'];
            }
        }

        return $visible;
    }
}

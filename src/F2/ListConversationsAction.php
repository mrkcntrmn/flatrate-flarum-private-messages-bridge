<?php

namespace Neoncube\FlarumPrivateMessages\F2;

use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Neoncube\FlarumPrivateMessages\Commands\HideMessage;
use Neoncube\FlarumPrivateMessages\Commands\NewMessage;
use Neoncube\FlarumPrivateMessages\Commands\ReadMessage;
use Neoncube\FlarumPrivateMessages\Commands\StartConversation;
use Neoncube\FlarumPrivateMessages\Conversation;
use Neoncube\FlarumPrivateMessages\ConversationAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Flarum 2 request handlers. Email and realtime delivery are not registered.
 */
final class ListConversationsAction extends ParticipantAction
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        if ($denied = $this->guestOrSuspended($actor)) {
            return $denied;
        }

        $conversations = Conversation::whereHas('recipients', function ($query) use ($actor) {
            $query->where('user_id', $actor->id);
        })->with(['recipients.user'])->orderBy('updated_at', 'desc')->get();

        $data = [];
        $included = [];
        $seen = [];
        foreach ($conversations as $conversation) {
            $doc = ConversationJson::document($conversation, $actor);
            $data[] = $doc['data'];
            foreach ($doc['included'] ?? [] as $row) {
                $key = $row['type'].':'.$row['id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $included[] = $row;
            }
        }

        $payload = ['data' => $data];
        if ($included !== []) {
            $payload['included'] = $included;
        }

        return new JsonResponse($payload, 200, ['Cache-Control' => 'private, no-store']);
    }
}

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
final class ListMessagesAction extends ParticipantAction
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        if ($denied = $this->guestOrSuspended($actor)) {
            return $denied;
        }

        $id = Arr::get($request->getQueryParams(), 'id');
        try {
            $conversation = $this->access->assertParticipantInConversationId($actor, $id);
        } catch (ModelNotFoundException $e) {
            return $this->denied(404, 'not_found');
        } catch (PermissionDeniedException $e) {
            return $this->denied(403, 'permission_denied');
        }

        $messages = [];
        $included = [];
        $seenUsers = [];
        foreach ($conversation->messages()->with('user')->orderBy('number')->get() as $message) {
            if (!empty($message->is_hidden)) {
                continue;
            }
            $messages[] = MessageJson::resource($message);
            $userId = (string) $message->user_id;
            if ($userId !== '' && !isset($seenUsers[$userId])) {
                $userDoc = MessageJson::includedUser($message->user);
                if ($userDoc !== null) {
                    $included[] = $userDoc;
                    $seenUsers[$userId] = true;
                }
            }
        }

        $payload = ['data' => $messages];
        if ($included !== []) {
            $payload['included'] = $included;
        }

        return new JsonResponse($payload, 200, ['Cache-Control' => 'private, no-store']);
    }
}

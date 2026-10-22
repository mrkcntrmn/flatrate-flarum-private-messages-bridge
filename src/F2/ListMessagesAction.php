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
        foreach ($conversation->messages()->orderBy('number')->get() as $message) {
            if (!empty($message->is_hidden)) {
                continue;
            }
            $messages[] = [
                'type' => 'messages',
                'id' => (string) $message->id,
                'attributes' => [
                    'number' => $message->number,
                    'content' => $message->message,
                    'userId' => (int) $message->user_id,
                ],
            ];
        }

        return new JsonResponse(['data' => $messages], 200, ['Cache-Control' => 'private, no-store']);
    }
}

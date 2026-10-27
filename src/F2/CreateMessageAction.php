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
final class CreateMessageAction extends ParticipantAction
{
    public function __construct(ConversationAccess $access, private BusDispatcher $bus)
    {
        parent::__construct($access);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        if ($denied = $this->guestOrSuspended($actor)) {
            return $denied;
        }

        $data = Arr::get($request->getParsedBody(), 'data', []);
        if (!is_array($data)) {
            $data = [];
        }
        $conversationId = $data['attributes']['conversationId'] ?? null;
        try {
            $this->access->assertParticipantInConversationId($actor, $conversationId);
        } catch (ModelNotFoundException $e) {
            return $this->denied(404, 'not_found');
        } catch (PermissionDeniedException $e) {
            return $this->denied(403, 'permission_denied');
        }

        $message = $this->bus->dispatch(new NewMessage($actor, $data, $conversationId));
        $message->loadMissing('user');

        $payload = [
            'data' => MessageJson::resource($message),
        ];
        $userDoc = MessageJson::includedUser($message->user);
        if ($userDoc !== null) {
            $payload['included'] = [$userDoc];
        }

        return new JsonResponse($payload, 201, ['Cache-Control' => 'private, no-store']);
    }
}

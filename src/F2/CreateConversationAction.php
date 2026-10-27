<?php

namespace Neoncube\FlarumPrivateMessages\F2;

use Flarum\Foundation\ValidationException;
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
final class CreateConversationAction extends ParticipantAction
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
        try {
            $conversation = $this->bus->dispatch(new StartConversation($actor, is_array($data) ? $data : []));
        } catch (PermissionDeniedException $e) {
            return $this->denied(403, 'permission_denied');
        } catch (ValidationException $e) {
            return $this->denied(422, 'validation_error');
        }

        return new JsonResponse(
            ConversationJson::document($conversation, $actor),
            201,
            ['Cache-Control' => 'private, no-store']
        );
    }
}

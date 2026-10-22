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
abstract class ParticipantAction implements RequestHandlerInterface
{
    public function __construct(protected ConversationAccess $access)
    {
    }

    protected function actor(ServerRequestInterface $request)
    {
        return $request->getAttribute('actor');
    }

    protected function denied(int $status, string $code): ResponseInterface
    {
        return new JsonResponse(
            ['errors' => [['status' => (string) $status, 'code' => $code]]],
            $status,
            ['Cache-Control' => 'private, no-store']
        );
    }

    protected function guestOrSuspended($actor): ?ResponseInterface
    {
        if (!$actor || empty($actor->id) || (method_exists($actor, 'isGuest') && $actor->isGuest())) {
            return $this->denied(403, 'permission_denied');
        }
        if (method_exists($actor, 'isSuspended') && $actor->isSuspended()) {
            return $this->denied(403, 'suspended_user');
        }
        if (!empty($actor->suspended_until)) {
            $until = $actor->suspended_until;
            $ts = $until instanceof \DateTimeInterface ? $until->getTimestamp() : strtotime((string) $until);
            if ($ts && $ts > time()) {
                return $this->denied(403, 'suspended_user');
            }
        }

        return null;
    }
}

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
        })->orderBy('updated_at', 'desc')->get();

        $data = [];
        foreach ($conversations as $conversation) {
            $data[] = ['type' => 'conversations', 'id' => (string) $conversation->id];
        }

        return new JsonResponse(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }
}

final class ShowConversationAction extends ParticipantAction
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

        return new JsonResponse([
            'data' => ['type' => 'conversations', 'id' => (string) $conversation->id],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}

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
                    'content' => $message->content,
                    'userId' => (int) $message->user_id,
                ],
            ];
        }

        return new JsonResponse(['data' => $messages], 200, ['Cache-Control' => 'private, no-store']);
    }
}

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
        $conversation = $this->bus->dispatch(new StartConversation($actor, is_array($data) ? $data : []));

        return new JsonResponse([
            'data' => ['type' => 'conversations', 'id' => (string) $conversation->id],
        ], 201, ['Cache-Control' => 'private, no-store']);
    }
}

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

        return new JsonResponse([
            'data' => ['type' => 'messages', 'id' => (string) $message->id],
        ], 201, ['Cache-Control' => 'private, no-store']);
    }
}

final class ReadMessageAction extends ParticipantAction
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

        $body = $request->getParsedBody();
        $attributes = is_array($body) ? ($body['data']['attributes'] ?? $body) : [];
        $data = is_array($attributes) ? $attributes : [];
        try {
            $this->access->assertParticipantInConversationId($actor, $data['conversationId'] ?? null);
        } catch (ModelNotFoundException $e) {
            return $this->denied(404, 'not_found');
        } catch (PermissionDeniedException $e) {
            return $this->denied(403, 'permission_denied');
        }

        $this->bus->dispatch(new ReadMessage($actor, $data));

        return new JsonResponse(['data' => ['type' => 'conversation-reads']], 200, ['Cache-Control' => 'private, no-store']);
    }
}

final class DeleteMessageAction extends ParticipantAction
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

        $messageId = Arr::get($request->getQueryParams(), 'id');
        try {
            $this->bus->dispatch(new HideMessage($messageId, $actor));
        } catch (ModelNotFoundException $e) {
            return $this->denied(404, 'not_found');
        } catch (PermissionDeniedException $e) {
            return $this->denied(403, 'permission_denied');
        }

        return new JsonResponse(null, 204, ['Cache-Control' => 'private, no-store']);
    }
}

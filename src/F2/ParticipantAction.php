<?php

namespace Neoncube\FlarumPrivateMessages\F2;

use Flarum\Http\RequestUtil;
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
        return RequestUtil::getActor($request);
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

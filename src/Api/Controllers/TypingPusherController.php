<?php

namespace Neoncube\FlarumPrivateMessages\Api\Controllers;

use Flarum\Api\Controller\AbstractShowController;
use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Neoncube\FlarumPrivateMessages\ConversationAccess;
use Psr\Http\Message\ServerRequestInterface;
use Pusher;
use Tobscure\JsonApi\Document;

class TypingPusherController extends AbstractShowController
{
    public $serializer = BasicUserSerializer::class;

    /** @var ConversationAccess */
    protected $access;

    public function __construct(ConversationAccess $access)
    {
        $this->access = $access;
    }

    public function data(ServerRequestInterface $request, Document $document)
    {
        $actor = $request->getAttribute('actor');
        $data = $request->getParsedBody() ?: [];

        if ($actor->isGuest()) {
            throw new PermissionDeniedException;
        }

        $conversationId = Arr::get($data, 'conversationId');
        if ($conversationId === null || $conversationId === '') {
            throw new PermissionDeniedException;
        }

        $conversation = $this->access->assertParticipantInConversationId($actor, $conversationId);
        $peer = $this->access->requireOneToOnePeer($actor, $conversation);

        // Never trust client-supplied userId as the push destination.
        if (resolve('container')->bound(Pusher::class)) {
            resolve('container')->make(Pusher::class)->trigger('private-user' . $peer->id, 'typing', [
                'conversationId' => $conversation->id
            ]);
        }

        return $actor;
    }
}

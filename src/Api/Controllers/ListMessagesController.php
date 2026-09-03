<?php

namespace Neoncube\FlarumPrivateMessages\Api\Controllers;

use Flarum\Api\Controller\AbstractListController;
use Tobscure\JsonApi\Document;
use Illuminate\Support\Arr;
use Neoncube\FlarumPrivateMessages\Api\Serializers\MessageSerializer;
use Neoncube\FlarumPrivateMessages\ConversationAccess;
use Neoncube\FlarumPrivateMessages\Message;
use Psr\Http\Message\ServerRequestInterface;

class ListMessagesController extends AbstractListController
{
    public $serializer = MessageSerializer::class;

    public $include = ['user'];

    /** @var ConversationAccess */
    protected $access;

    public function __construct(ConversationAccess $access)
    {
        $this->access = $access;
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $conversationId = Arr::get($request->getQueryParams(), 'id');
        $actor = $request->getAttribute('actor');
        $limit = $this->extractLimit($request);
        $offset = array_key_exists('offset', $request->getQueryParams())
            ? $request->getQueryParams()['offset']
            : 0;

        $this->access->assertParticipantInConversationId($actor, $conversationId);

        return Message::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'desc')
            ->skip($offset)
            ->take($limit)
            ->get();
    }
}

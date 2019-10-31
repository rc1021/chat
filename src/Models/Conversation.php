<?php

namespace Musonza\Chat\Models;

use Chat;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Musonza\Chat\BaseModel;
use Musonza\Chat\ConfigurationManager;
use Musonza\Chat\Eventing\ParticipantsJoined;
use Musonza\Chat\Eventing\ParticipantsLeft;
use Musonza\Chat\Exceptions\DeletingConversationWithParticipantsException;
use Musonza\Chat\Exceptions\DirectMessagingExistsException;
use Musonza\Chat\Exceptions\InvalidDirectMessageNumberOfParticipants;

class Conversation extends BaseModel
{
    protected $table = ConfigurationManager::CONVERSATIONS_TABLE;
    protected $fillable = ['data'];
    protected $casts = [
        'data'           => 'array',
        'direct_message' => 'boolean',
        'private'        => 'boolean',
    ];

    public function delete()
    {
        if ($this->participants()->count()) {
            throw new DeletingConversationWithParticipantsException();
        }

        return parent::delete();
    }

    /**
     * Conversation participants.
     *
     * @return HasMany
     */
    public function participants()
    {
        return $this->hasMany(Participation::class);
    }

    public function getParticipants()
    {
        return $this->participants()->get()->pluck('messageable');
    }

    /**
     * Return the recent message in a Conversation.
     *
     * @return HasOne
     */
    public function last_message()
    {
        return $this->hasOne(Message::class)
            ->orderBy($this->tablePrefix.'messages.id', 'desc')
            ->with('participation');
    }

    /**
     * Messages in conversation.
     *
     * @return HasMany
     */
    public function messages()
    {
        return $this->hasMany(Message::class, 'conversation_id'); //->with('sender');
    }

    /**
     * Get messages for a conversation.
     *
     * @param Model $participant
     * @param array $paginationParams
     * @param bool  $deleted
     *
     * @return LengthAwarePaginator|HasMany|Builder
     */
    public function getMessages(Model $participant, $paginationParams, $deleted = false)
    {
        return $this->getConversationMessages($participant, $paginationParams, $deleted);
    }

    public function getParticipantConversations($participant, array $options)
    {
        return $this->getConversationsList($participant, $options);
    }

    public function participantFromSender(Model $sender)
    {
        return $this->participants()->where([
            'conversation_id'  => $this->getKey(),
            'messageable_id'   => $sender->getKey(),
            'messageable_type' => get_class($sender),
        ])->first();
    }

    /**
     * Add user to conversation.
     *
     * @param $participants
     *
     * @return Conversation
     */
    public function addParticipants(array $participants): self
    {
        foreach ($participants as $participant) {
            $participant->joinConversation($this);
        }

        event(new ParticipantsJoined($this, $participants));

        return $this;
    }

    /**
     * Remove participant from conversation.
     *
     * @param  $participants
     *
     * @return Conversation
     */
    public function removeParticipant($participants)
    {
        if (is_array($participants)) {
            foreach ($participants as $participant) {
                $participant->leaveConversation($this->getKey());
            }

            event(new ParticipantsLeft($this, $participants));

            return $this;
        }

        $participants->leaveConversation($this->getKey());

        event(new ParticipantsLeft($this, [$participants]));

        return $this;
    }

    /**
     * Starts a new conversation.
     *
     * @param array $participants
     * @param array $data
     *
     * @return Conversation
     */
    public function start(array $participants, $data = []): self
    {
        /** @var Conversation $conversation */
        $conversation = $this->create(['data' => $data]);

        if ($participants) {
            $conversation->addParticipants($participants);
        }

        return $conversation;
    }

    /**
     * Sets conversation as public or private.
     *
     * @param bool $isPrivate
     *
     * @return Conversation
     */
    public function makePrivate($isPrivate = true)
    {
        $this->private = $isPrivate;
        $this->save();

        return $this;
    }

    /**
     * Sets conversation as direct message.
     *
     * @param bool $isDirect
     *
     * @throws InvalidDirectMessageNumberOfParticipants
     * @throws DirectMessagingExistsException
     *
     * @return Conversation
     */
    public function makeDirect($isDirect = true)
    {
        if ($this->participants()->count() > 2) {
            throw new InvalidDirectMessageNumberOfParticipants();
        }

        $this->ensureNoDirectMessagingExist();

        $this->direct_message = $isDirect;
        $this->save();

        return $this;
    }

    /**
     * @throws DirectMessagingExistsException
     */
    private function ensureNoDirectMessagingExist()
    {
        $participants = $this->participants()->get()->pluck('messageable');
        $common = Chat::conversations()->between($participants[0], $participants[1]);

        if (!is_null($common)) {
            throw new DirectMessagingExistsException();
        }
    }

    /**
     * Gets conversations for a specific participant.
     *
     * @param Model $participant
     * @param bool  $isDirectMessage
     *
     * @return Collection
     */
    public function participantConversations(Model $participant, bool $isDirectMessage = false): Collection
    {
        $conversations = $participant->participation->pluck('conversation');

        return $isDirectMessage ? $conversations->where('direct_message', 1) : $conversations;
    }

    /**
     * Get unread notifications.
     *
     * @param Model $participant
     *
     * @return Collection
     */
    public function unReadNotifications(Model $participant): Collection
    {
        $notifications = MessageNotification::where([
            ['messageable_id', '=', $participant->getKey()],
            ['messageable_type', '=', get_class($participant)],
            ['conversation_id', '=', $this->id],
            ['is_seen', '=', 0],
        ])->get();

        return $notifications;
    }

    /**
     * Gets the notifications for the participant.
     *
     * @param  $participant
     * @param bool $readAll
     *
     * @return MessageNotification
     */
    public function getNotifications($participant, $readAll = false)
    {
        return $this->notifications($participant, $readAll);
    }

    /**
     * Clears participant conversation.
     *
     * @param $participant
     *
     * @return void
     */
    public function clear($participant): void
    {
        $this->clearConversation($participant);
    }

    /**
     * Marks all the messages in a conversation as read for the participant.
     *
     * @param Model $participant
     *
     * @return void
     */
    public function readAll(Model $participant): void
    {
        $this->getNotifications($participant, true);
    }

    /**
     * Get messages in conversation for the specific participant.
     *
     * @param Model $participant
     * @param $paginationParams
     * @param $deleted
     *
     * @return LengthAwarePaginator|HasMany|Builder
     */
    private function getConversationMessages(Model $participant, $paginationParams, $deleted)
    {
        $messages = $this->messages()
            ->join($this->tablePrefix.'message_notifications', $this->tablePrefix.'message_notifications.message_id', '=', $this->tablePrefix.'messages.id')
            ->where($this->tablePrefix.'message_notifications.messageable_type', get_class($participant))
            ->where($this->tablePrefix.'message_notifications.messageable_id', $participant->getKey());
        $messages = $deleted ? $messages->whereNotNull($this->tablePrefix.'message_notifications.deleted_at') : $messages->whereNull($this->tablePrefix.'message_notifications.deleted_at');
        $messages = $messages->orderBy($this->tablePrefix.'messages.id', $paginationParams['sorting'])
            ->paginate(
                $paginationParams['perPage'],
                [
                    $this->tablePrefix.'message_notifications.updated_at as read_at',
                    $this->tablePrefix.'message_notifications.deleted_at as deleted_at',
                    $this->tablePrefix.'message_notifications.messageable_id',
                    $this->tablePrefix.'message_notifications.id as notification_id',
                    $this->tablePrefix.'messages.*',
                ],
                $paginationParams['pageName'],
                $paginationParams['page']
            );

        return $messages;
    }

    /**
     * @param Model $participant
     * @param $options
     *
     * @return mixed
     */
    private function getConversationsList(Model $participant, $options)
    {
        /** @var Builder $paginator */
        $paginator = $participant->participation()
            ->join($this->tablePrefix.'conversations as c', $this->tablePrefix.'participation.conversation_id', '=', 'c.id')
            ->with([
                'conversation.last_message' => function ($query) use ($participant) {
                    $query->join($this->tablePrefix.'message_notifications', $this->tablePrefix.'message_notifications.message_id', '=', $this->tablePrefix.'messages.id')
                        ->select($this->tablePrefix.'message_notifications.*', $this->tablePrefix.'messages.*')
                        ->where($this->tablePrefix.'message_notifications.messageable_id', $participant->getKey())
                        ->where($this->tablePrefix.'message_notifications.messageable_type', get_class($participant))
                        ->whereNull($this->tablePrefix.'message_notifications.deleted_at');
                },
                'conversation.participants.messageable',
            ])
            ->where($this->tablePrefix.'participation.messageable_id', $participant->getKey());

        if (isset($options['filters']['private'])) {
            $paginator = $paginator->where('c.private', (bool) $options['filters']['private']);
        }

        if (isset($options['filters']['direct_message'])) {
            $paginator = $paginator->where('c.direct_message', (bool) $options['filters']['direct_message']);
        }

        return $paginator
            ->orderBy('c.updated_at', 'DESC')
            ->orderBy('c.id', 'DESC')
            ->distinct('c.id')
            ->paginate($options['perPage'], [$this->tablePrefix.'participation.*'], $options['pageName'], $options['page']);
    }

    private function notifications(Model $participant, $readAll)
    {
        $notifications = MessageNotification::where('messageable_id', $participant->getKey())
            ->where($this->tablePrefix.'message_notifications.messageable_type', get_class($participant))
            ->where('conversation_id', $this->id);

        if ($readAll) {
            return $notifications->update(['is_seen' => 1]);
        }

        return $notifications->get();
    }

    private function clearConversation($participant): void
    {
        MessageNotification::where('messageable_id', $participant->getKey())
            ->where($this->tablePrefix.'message_notifications.messageable_type', get_class($participant))
            ->where('conversation_id', $this->getKey())
            ->delete();
    }

    public function isDirectMessage(): bool
    {
        return (bool) $this->direct_message;
    }
}

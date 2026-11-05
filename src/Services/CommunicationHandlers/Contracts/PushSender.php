<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

/**
 * Delivers push notifications. The package does not ship one: the host app binds
 * its own (Expo, FCM…) in the container. Without a binding the PUSH channel stays
 * disabled, so apps that have no mobile app never see it.
 */
interface PushSender
{
    /**
     * @param array<int, array{to: string, title: string, body: string, data?: array, channelId?: string}> $messages
     */
    public function send(array $messages): void;
}

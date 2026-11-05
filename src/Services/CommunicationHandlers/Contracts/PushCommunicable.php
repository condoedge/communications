<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

interface PushCommunicable extends Communicable
{
    /**
     * Push tokens of the devices where the communication will be sent (e.g. Expo
     * "ExponentPushToken[…]"). An empty array skips the recipient on this channel.
     *
     * @return string[]
     */
    public function getPushTokens(): array;
}

<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

interface ChannelAware
{
    /**
     * Return false to suppress this channel for this recipient even if the
     * recipient implements the channel interface.
     *
     * @param string $channelInterface Fully-qualified channel contract FQCN
     *                                 (e.g. EmailCommunicable::class).
     * @return bool
     */
    public function acceptsChannel(string $channelInterface): bool;
}

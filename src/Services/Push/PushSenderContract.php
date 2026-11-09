<?php

namespace Condoedge\Communications\Services\Push;

/**
 * Delivers push notifications. The package ships no implementation: the host app binds its own
 * (Expo, FCM…) in the container. Without a binding the PUSH channel is not sendable, so an app
 * with no mobile app never offers it and never attempts it.
 */
interface PushSenderContract
{
    /** Throwing marks this recipient FAILED in the send log; the other recipients still go out. */
    public function send(PushMessage $message): void;
}

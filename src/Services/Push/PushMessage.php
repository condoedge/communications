<?php

namespace Condoedge\Communications\Services\Push;

/**
 * One push notification for one recipient, on every device they registered. Provider-neutral on
 * purpose: the host's PushSenderContract maps it onto whatever it talks to (Expo, FCM, APNs).
 */
final class PushMessage
{
    /**
     * @param string[] $tokens the recipient's device tokens, never empty
     * @param array<string, mixed> $data what the app receives on tap, e.g. ['route' => '/invoices/42']
     * @param string|null $androidChannel Android notification channel; null leaves the provider's default
     */
    public function __construct(
        public readonly array $tokens,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $androidChannel = null,
    ) {
    }
}

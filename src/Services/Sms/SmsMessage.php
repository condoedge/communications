<?php

namespace Condoedge\Communications\Services\Sms;

/** One text message for one recipient, already rendered as plain text. */
final class SmsMessage
{
    /** @param array<string, mixed> $params the context of this recipient's message */
    public function __construct(
        public readonly string $phone,
        public readonly string $content,
        public readonly array $params = [],
    ) {
    }
}

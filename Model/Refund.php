<?php

namespace Omnitrade\Model;

/** Money given back, as the provider recorded it. */
final readonly class Refund
{
    public function __construct(
        public string $provider,
        /** The provider's refund id (Stripe's re_...). */
        public string $reference,
        public Money $amount,
        public Status $status,
        /** The transaction refunded. */
        public ?string $transactionReference = null,
        public ?string $message = null,
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
    ) {
    }
}

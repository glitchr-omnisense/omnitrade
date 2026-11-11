<?php

namespace Omnitrade\Model;

/** A commerce platform's order (Shopify, WooCommerce): the sale as it stands there. */
final readonly class PlatformOrder
{
    public function __construct(
        public string $provider,
        /** The platform's id. */
        public string $reference,
        /** The number people see ("#1042"). */
        public ?string $number,
        public Status $status,
        public Money $total,
        public ?Customer $customer = null,
        /** @var list<Line> */
        public array $lines = [],
        /** The platform's own payment state and fulfilment state, as it names them. */
        public ?string $financialStatus = null,
        public ?string $fulfillmentStatus = null,
        public ?\DateTimeImmutable $createdAt = null,
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
    ) {
    }
}

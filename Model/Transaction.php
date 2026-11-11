<?php

namespace Omnitrade\Model;

/**
 * A payment as the provider knows it: its reference there, where it stands,
 * and - PENDING with a $redirectUrl - the page the buyer must be sent to.
 * $raw keeps the provider's whole answer, for what this model leaves out.
 */
final readonly class Transaction
{
    public function __construct(
        public string $provider,
        /** The provider's id: a Checkout session, a payment intent, a PayPal order... */
        public string $reference,
        public Status $status,
        public ?Money $amount = null,
        public ?string $redirectUrl = null,
        /** The provider's word on it: a refusal's reason, a pending's cause. */
        public ?string $message = null,
        public ?string $providerCode = null,
        /** The provider's payment method, as it names it ("card", "paypal", "sepa_debit"). */
        public ?string $method = null,
        public ?Money $refunded = null,
        /** @var array<string, mixed> */
        public array $metadata = [],
        public ?\DateTimeImmutable $createdAt = null,
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
    ) {
    }

    /** The buyer has somewhere to go: a hosted page, a 3-D Secure challenge. */
    public function isRedirect(): bool
    {
        return null !== $this->redirectUrl && Status::PENDING === $this->status;
    }

    public function isPaid(): bool
    {
        return Status::PAID === $this->status;
    }

    public function isPending(): bool
    {
        return Status::PENDING === $this->status;
    }

    public function isRefused(): bool
    {
        return Status::REFUSED === $this->status;
    }
}

<?php

namespace Omnitrade\Model;

/**
 * A subscription as the provider has it: who pays (the provider's customer),
 * for what (its price), whether it runs, until when the paid period lasts
 * and whether it stops there.
 */
final readonly class Subscription
{
    public const ACTIVE = 'active';
    public const TRIALING = 'trialing';
    public const PAST_DUE = 'past_due';
    public const UNPAID = 'unpaid';
    public const CANCELLED = 'cancelled';
    public const INCOMPLETE = 'incomplete';
    public const PAUSED = 'paused';

    public function __construct(
        public string $provider,
        /** The provider's id: "sub_1Nv0...". */
        public string $reference,
        public string $status,
        /** The provider's customer ("cus_..."): what the portal is opened for. */
        public ?string $customer = null,
        public ?Money $amount = null,
        /** day, week, month, year. */
        public ?string $interval = null,
        public int $intervalCount = 1,
        public ?\DateTimeImmutable $currentPeriodStart = null,
        public ?\DateTimeImmutable $currentPeriodEnd = null,
        /** It runs to the end of the period, then stops. */
        public bool $cancelAtPeriodEnd = false,
        public ?\DateTimeImmutable $cancelledAt = null,
        /** The provider's price ("price_...") or product it is for. */
        public ?string $price = null,
        /** @var array<string, mixed> yours, given at Subscribe */
        public array $metadata = [],
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
    ) {
    }

    /** What it gives is due: running or on trial. */
    public function isActive(): bool
    {
        return \in_array($this->status, [self::ACTIVE, self::TRIALING], true);
    }

    public function isCancelled(): bool
    {
        return self::CANCELLED === $this->status;
    }
}

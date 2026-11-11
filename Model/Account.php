<?php

namespace Omnitrade\Model;

/**
 * A connected account: somebody else's account at the provider, opened
 * through the platform (Stripe Connect), that payments may be sent to.
 * It can be paid to once the provider has what it asks of its holder:
 * $chargesEnabled and $payoutsEnabled say so; $requirements lists what is
 * still missing.
 */
final readonly class Account
{
    public const EXPRESS = 'express';
    public const STANDARD = 'standard';
    public const CUSTOM = 'custom';

    public function __construct(
        public string $provider,
        /** The provider's id: "acct_1Nv0...". */
        public string $reference,
        public string $type = self::EXPRESS,
        public ?string $country = null,
        public ?string $email = null,
        /** The holder gave everything the onboarding asked. */
        public bool $detailsSubmitted = false,
        public bool $chargesEnabled = false,
        public bool $payoutsEnabled = false,
        /** @var list<string> what the provider still wants ("external_account", "individual.verification.document") */
        public array $requirements = [],
        public ?string $defaultCurrency = null,
        /** @var array<string, mixed> */
        public array $metadata = [],
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
    ) {
    }

    /** Payments may be sent to it, and it may be paid out. */
    public function isReady(): bool
    {
        return $this->chargesEnabled && $this->payoutsEnabled;
    }
}

<?php

namespace Omnitrade\Model;

/**
 * A payment method the provider keeps for one of its customers (Request\SetupMethod), to charge
 * later without the buyer on a page: Payment::$savedMethod. Only what a buyer recognises it by -
 * a card's brand, its last four digits, its expiry; a wallet's e-mail - never a number: the
 * provider holds the method, the application its reference.
 */
final readonly class SavedMethod
{
    public function __construct(
        /** The provider's id for it: Stripe's pm_..., a PayPal vault token. */
        public string $reference,
        /** The provider's customer it belongs to (Stripe's cus_...), when the provider has one. */
        public ?string $customer,
        /** As the provider names it: "card", "paypal", "sepa_debit"... */
        public string $code,
        /** What to show: "Visa •••• 4242", "PayPal alice@example.org". */
        public string $label,
        /** One of PaymentMethod's kinds. */
        public string $kind = PaymentMethod::OTHER,
        /** A card's brand as the provider names it: "visa", "mastercard"... */
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?int $expiryMonth = null,
        public ?int $expiryYear = null,
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
    ) {
    }

    /** Whether a card's expiry is past at $at (now by default); a method without one never is. */
    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        if (null === $this->expiryMonth || null === $this->expiryYear) {
            return false;
        }
        $at ??= new \DateTimeImmutable();

        return (int) $at->format('Y') * 12 + (int) $at->format('n') > $this->expiryYear * 12 + $this->expiryMonth;
    }
}

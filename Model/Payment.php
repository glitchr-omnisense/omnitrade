<?php

namespace Omnitrade\Model;

/**
 * What to charge, and everything a provider may want with it. The amount is
 * the truth; the lines are for the buyer's eyes (a hosted page), and a
 * provider that cannot take them as given charges the amount as one line.
 *
 * $reference is yours - the order's -, sent to the provider as the merchant
 * reference. $idempotencyKey makes the same purchase asked twice happen once,
 * at providers that honour one (Stripe does): give it per attempt, and keep
 * it with the attempt.
 *
 * $destination and $applicationFee make it a payment for someone else: taken
 * by the platform, sent to a connected account (Request\CreateAccount), the
 * platform keeping its fee.
 *
 * $savedMethod charges a method the provider keeps for the buyer
 * (Request\SetupMethod) without sending them to a page (off-session).
 */
final readonly class Payment
{
    public function __construct(
        public Money $amount,
        public string $reference,
        public ?string $description = null,
        public ?Customer $customer = null,
        /** @var list<Line> */
        public array $lines = [],
        /** Posting, on top of the lines; and money off, taken from them. Both inside $amount. */
        public ?Money $shipping = null,
        public ?Money $discount = null,
        /** Where the buyer comes back after a hosted page, paid or not. */
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public ?string $idempotencyKey = null,
        /** A provider's payment method to insist on ("card", "sepa_debit"...), else its choice. */
        public ?string $method = null,
        /** A mention to print on the page and the receipt: why there is no VAT, for instance. */
        public ?string $notice = null,
        /** @var array<string, scalar> kept by providers that keep metadata, given back on notify() */
        public array $metadata = [],
        /** BCP 47, for the provider's page. */
        public ?string $locale = null,
        /**
         * A connected account the money goes to (Stripe Connect's destination
         * charge: "acct_..."): the platform takes the payment and passes it on,
         * keeping $applicationFee. Null: the money is the platform's own.
         */
        public ?string $destination = null,
        /** What the platform keeps of a payment sent to $destination; inside $amount. */
        public ?Money $applicationFee = null,
        /**
         * A method the provider keeps for the buyer (Request\SetupMethod: SavedMethod::$reference),
         * charged at once with no page to go through - $customer->reference names the provider's
         * customer it belongs to, where the provider has customers. The buyer's bank may still ask
         * them to confirm (3-D Secure): the transaction is then PENDING with the provider's page
         * as its redirectUrl ($returnUrl is where they come back), and notify() or fetch() says how
         * it ended. Null: the provider's page, as before.
         */
        public ?string $savedMethod = null,
    ) {
        if (null !== $applicationFee && null === $destination) {
            throw new \InvalidArgumentException('An application fee is kept on a payment sent to a destination account.');
        }
        if (null !== $applicationFee && ($applicationFee->currency !== $amount->currency || $applicationFee->amount > $amount->amount || $applicationFee->amount < 0)) {
            throw new \InvalidArgumentException('The application fee is in the payment\'s currency and within its amount.');
        }
    }

    /** Whether the lines, the shipping and the discount add up to the amount, in its currency: then a page may list them. */
    public function linesAddUp(): bool
    {
        if (!$this->lines) {
            return false;
        }
        $currency = $this->amount->currency;
        foreach ([$this->shipping, $this->discount] as $extra) {
            if ($extra && $extra->currency !== $currency) {
                return false;
            }
        }
        $sum = ($this->shipping->amount ?? 0) - ($this->discount->amount ?? 0);
        foreach ($this->lines as $line) {
            if ($line->unitAmount->currency !== $currency) {
                return false;
            }
            $sum += $line->total()->amount;
        }

        return $sum === $this->amount->amount;
    }
}

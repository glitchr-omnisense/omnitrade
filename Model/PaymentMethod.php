<?php

namespace Omnitrade\Model;

/** One way a provider takes money: a card, a wallet, a bank debit. */
final readonly class PaymentMethod
{
    public const CARD = 'card';
    public const WALLET = 'wallet';
    public const BANK = 'bank';
    public const BUY_NOW_PAY_LATER = 'bnpl';
    public const OTHER = 'other';

    public function __construct(
        /** As the provider names it: "card", "paypal", "sepa_debit", "klarna"... */
        public string $code,
        public string $label,
        /** One of the constants above. */
        public string $kind = self::OTHER,
        /** @var list<string> the currencies it takes, empty for any */
        public array $currencies = [],
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
    ) {
    }
}

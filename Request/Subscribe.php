<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Payment;
use Omnitrade\Model\Transaction;

/**
 * Start a subscription: the payment's amount is taken now and then every
 * interval, on a page the buyer is sent to. Result: the Transaction -
 * PENDING with a redirectUrl; notify() then tells of the first payment and,
 * through Notification::$subscription, of the subscription and its life.
 *
 * The payment's lines describe what is subscribed to; a provider that
 * keeps its own prices takes $price (a Stripe "price_...") instead of the
 * amount.
 *
 * @extends Request<Transaction>
 */
final class Subscribe extends Request
{
    public const INTERVALS = ['day', 'week', 'month', 'year'];

    public function __construct(
        public readonly Payment $payment,
        public readonly string $interval = 'month',
        public readonly int $intervalCount = 1,
        /** The provider's own price, when the plan is kept there. */
        public readonly ?string $price = null,
        public readonly ?int $trialDays = null,
        /** The provider's customer, when the buyer already is one ("cus_..."). */
        public readonly ?string $customer = null,
    ) {
        if (!\in_array($interval, self::INTERVALS, true)) {
            throw new \InvalidArgumentException(sprintf('A subscription renews every day, week, month or year, not every "%s".', $interval));
        }
        if ($intervalCount < 1) {
            throw new \InvalidArgumentException('A subscription renews every interval at least.');
        }
    }

    public function getTransaction(): ?Transaction
    {
        return $this->getResult();
    }
}

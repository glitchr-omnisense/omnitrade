<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Subscription;

/**
 * Stop a subscription: at the end of the period already paid (the default -
 * what was paid for is kept until then) or at once. Result: the Subscription
 * as it stands after.
 *
 * @extends Request<Subscription>
 */
final class CancelSubscription extends Request
{
    public function __construct(
        public readonly string $reference,
        public readonly bool $atPeriodEnd = true,
    ) {
    }

    public function getSubscription(): ?Subscription
    {
        return $this->getResult();
    }
}

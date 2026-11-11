<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Subscription;

/**
 * A subscription as the provider has it now. Result: the Subscription.
 *
 * @extends Request<Subscription>
 */
final class FetchSubscription extends Request
{
    public function __construct(public readonly string $reference)
    {
    }

    public function getSubscription(): ?Subscription
    {
        return $this->getResult();
    }
}

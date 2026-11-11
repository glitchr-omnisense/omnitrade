<?php

namespace Omnitrade\Request;

/**
 * The provider's page where a subscriber changes their card, reads their
 * invoices or stops their subscription. Result: its URL, valid a short
 * while. $customer is the provider's customer (Subscription::$customer).
 *
 * @extends Request<string>
 */
final class SubscriptionPortal extends Request
{
    public function __construct(
        public readonly string $customer,
        public readonly string $returnUrl,
        /** BCP 47, for the provider's page. */
        public readonly ?string $locale = null,
    ) {
    }

    public function getUrl(): ?string
    {
        return $this->getResult();
    }
}

<?php

namespace Omnitrade\Request;

/**
 * Forget a saved payment method at the provider: it can no longer be charged. Only a method of
 * $customer: one of another customer's is refused (a ProviderException), so that a reference
 * sent by a client never removes someone else's card. Result: true once removed.
 *
 * @extends Request<bool>
 */
final class RemoveSavedMethod extends Request
{
    public function __construct(
        /** The provider's customer the method must belong to. */
        public readonly string $customer,
        /** SavedMethod::$reference. */
        public readonly string $reference,
    ) {
    }

    public function isRemoved(): bool
    {
        return true === $this->getResult();
    }
}

<?php

namespace Omnitrade\Model;

/**
 * A payment method being saved on the provider's own page (Request\SetupMethod): the page to send
 * the buyer to, and the customer the method will belong to there - made for the occasion when the
 * buyer was none yet, so keep $customer: it is what Request\FetchSavedMethods and
 * Payment::$customer->reference name afterwards.
 */
final readonly class MethodSetup
{
    public function __construct(
        public string $provider,
        /** The provider's id for the setup: a Checkout session in setup mode, a PayPal setup token. */
        public string $reference,
        /** The provider's page where the buyer saves the method. */
        public string $redirectUrl,
        /** The provider's customer the method is saved for (Stripe's cus_...), when the provider has one. */
        public ?string $customer = null,
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
    ) {
    }
}

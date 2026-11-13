<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Customer;
use Omnitrade\Model\MethodSetup;

/**
 * Save a payment method for later, on the provider's own page: the buyer types their card (or
 * signs in to their wallet) there, never here, and the provider keeps it for its customer -
 * $customer->reference when the buyer already is one, else a customer made from $customer's
 * e-mail and name. Result: the MethodSetup, its page to send the buyer to and the customer.
 * Once they are back on $returnUrl the method is listed by FetchSavedMethods and charged by a
 * Payment naming it ($savedMethod). Optional: a gateway that supports() it can, the others cannot.
 *
 * @extends Request<MethodSetup>
 */
final class SetupMethod extends Request
{
    /** @param array<string, scalar> $metadata kept by providers that keep metadata */
    public function __construct(
        public readonly Customer $customer,
        /** Where the buyer comes back once the method is saved. */
        public readonly string $returnUrl,
        /** Where they come back when they give up; $returnUrl when null. */
        public readonly ?string $cancelUrl = null,
        /** Makes the same setup asked twice happen once, at providers that honour one. */
        public readonly ?string $idempotencyKey = null,
        public readonly array $metadata = [],
        /** BCP 47, for the provider's page. */
        public readonly ?string $locale = null,
        /** A provider's method to save ("card", "sepa_debit"...), else its choice. */
        public readonly ?string $method = null,
    ) {
    }

    public function getSetup(): ?MethodSetup
    {
        return $this->getResult();
    }
}

<?php

namespace Omnitrade\Request;

use Omnitrade\Model\SavedMethod;

/**
 * The buyer is back on SetupMethod's $returnUrl: the setup is completed at the provider and the
 * method it saved is answered. Some providers need the step (PayPal: the approved setup token is
 * turned into a payment token, and until then FetchSavedMethods does not list it), others only
 * read what the page saved (Stripe: the Checkout session's SetupIntent and its PaymentMethod) -
 * a host asks it whenever the gateway supports() it, the same code for every provider.
 *
 * Only a setup of $customer when one is named: another customer's is refused (a
 * ProviderException, code NOT_THIS_CUSTOMER), so that a reference brought back in a URL never
 * completes someone else's setup. A setup the buyer did not finish - cancelled, still open,
 * expired - is a ProviderException, code NOT_COMPLETED: nothing saved, ask SetupMethod again.
 * Asked twice, it answers the same method and saves nothing more. Result: the SavedMethod.
 *
 * @extends Request<SavedMethod>
 */
final class CompleteMethodSetup extends Request
{
    /** ProviderException::$providerCode of a setup the buyer did not finish. */
    public const NOT_COMPLETED = 'setup_not_completed';

    /** ProviderException::$providerCode of a setup (or a method) that is not the named customer's. */
    public const NOT_THIS_CUSTOMER = 'not_this_customer';

    public function __construct(
        /** The provider's id for the setup: MethodSetup::$reference. */
        public readonly string $reference,
        /** The provider's customer the setup must be for: MethodSetup::$customer. */
        public readonly ?string $customer = null,
    ) {
    }

    public function getMethod(): ?SavedMethod
    {
        return $this->getResult();
    }
}

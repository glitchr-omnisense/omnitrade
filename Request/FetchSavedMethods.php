<?php

namespace Omnitrade\Request;

use Omnitrade\Model\SavedMethod;

/**
 * The payment methods the provider keeps for one of its customers (SetupMethod), as it has them
 * now: a method removed at the provider, or expired, is not hidden by anything kept here.
 * Result: list<SavedMethod>, newest first where the provider says.
 *
 * @extends Request<list<SavedMethod>>
 */
final class FetchSavedMethods extends Request
{
    public function __construct(
        /** The provider's customer: MethodSetup::$customer. */
        public readonly string $customer,
    ) {
    }

    /** @return list<SavedMethod> */
    public function getMethods(): array
    {
        return $this->getResult() ?? [];
    }
}

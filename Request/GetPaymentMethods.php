<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Money;
use Omnitrade\Model\PaymentMethod;

/**
 * What the provider can take, for this amount and country when given. Result: PaymentMethod[]
 *
 * @extends Request<list<PaymentMethod>>
 */
final class GetPaymentMethods extends Request
{
    public function __construct(public readonly ?Money $for = null, public readonly ?string $country = null)
    {
    }

    /** @return PaymentMethod[] */
    public function getMethods(): array
    {
        return $this->getResult() ?? [];
    }
}

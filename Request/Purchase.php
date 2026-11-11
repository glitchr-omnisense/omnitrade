<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Payment;
use Omnitrade\Model\Transaction;

/**
 * Take the money. Result: the Transaction - PAID when it is taken at once,
 * PENDING with a redirectUrl when the buyer must go and pay on a page and
 * come back (then notify() or fetch() says).
 *
 * @extends Request<Transaction>
 */
final class Purchase extends Request
{
    public function __construct(public readonly Payment $payment)
    {
    }

    public function getTransaction(): ?Transaction
    {
        return $this->getResult();
    }
}

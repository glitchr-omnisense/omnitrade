<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Payment;
use Omnitrade\Model\Transaction;

/**
 * Reserve the money; Capture takes it, VoidAuthorization lets it go. Result: the Transaction.
 *
 * @extends Request<Transaction>
 */
final class Authorize extends Request
{
    public function __construct(public readonly Payment $payment)
    {
    }

    public function getTransaction(): ?Transaction
    {
        return $this->getResult();
    }
}

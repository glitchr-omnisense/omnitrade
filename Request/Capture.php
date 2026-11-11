<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Money;
use Omnitrade\Model\Transaction;

/**
 * Take the money reserved by an authorization, all of it or $amount. Result: the Transaction.
 *
 * @extends Request<Transaction>
 */
final class Capture extends Request
{
    public function __construct(
        public readonly string $reference,
        public readonly ?Money $amount = null,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public function getTransaction(): ?Transaction
    {
        return $this->getResult();
    }
}

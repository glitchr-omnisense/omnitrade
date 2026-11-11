<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Transaction;

/**
 * Let an authorization go without taking the money. Result: the Transaction, CANCELLED.
 *
 * @extends Request<Transaction>
 */
final class VoidAuthorization extends Request
{
    public function __construct(public readonly string $reference)
    {
    }

    public function getTransaction(): ?Transaction
    {
        return $this->getResult();
    }
}

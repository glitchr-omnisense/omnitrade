<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Transaction;

/**
 * The transaction as the provider has it now. Result: the Transaction.
 *
 * @extends Request<Transaction>
 */
final class FetchTransaction extends Request
{
    public function __construct(public readonly string $reference)
    {
    }

    public function getTransaction(): ?Transaction
    {
        return $this->getResult();
    }
}

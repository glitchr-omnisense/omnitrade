<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Account;

/**
 * A connected account as the provider has it now: ready or not, and what is missing. Result: the Account.
 *
 * @extends Request<Account>
 */
final class FetchAccount extends Request
{
    public function __construct(public readonly string $reference)
    {
    }

    public function getAccount(): ?Account
    {
        return $this->getResult();
    }
}

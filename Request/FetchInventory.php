<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Stock;

/**
 * What is left of these variants (their ids at the platform; none: every
 * variant it tracks). Result: Stock[], one per variant answered.
 *
 * @extends Request<list<Stock>>
 */
final class FetchInventory extends Request
{
    public function __construct(
        /** @var list<string> */
        public readonly array $references = [],
    ) {
    }

    /** @return list<Stock> */
    public function getStocks(): array
    {
        return $this->getResult() ?? [];
    }
}

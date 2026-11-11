<?php

namespace Omnitrade\Request;

use Omnitrade\Model\PlatformOrder;

/**
 * A commerce platform's order, by its id there. Result: the PlatformOrder.
 *
 * @extends Request<PlatformOrder>
 */
final class FetchOrder extends Request
{
    public function __construct(public readonly string $reference)
    {
    }

    public function getOrder(): ?PlatformOrder
    {
        return $this->getResult();
    }
}

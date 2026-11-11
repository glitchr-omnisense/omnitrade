<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Product;
use Omnitrade\Model\Reference;

/**
 * One product, by the platform's id or the address of its page. Result: the
 * Product, or null when there is no such product.
 *
 * @extends Request<?Product>
 */
final class FetchProduct extends Request
{
    public readonly Reference $reference;

    public function __construct(Reference|string $reference)
    {
        $this->reference = Reference::of($reference);
    }

    public function getProduct(): ?Product
    {
        return $this->getResult();
    }
}

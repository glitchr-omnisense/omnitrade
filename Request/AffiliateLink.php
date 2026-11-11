<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Reference;

/**
 * The address that sends a buyer to a product with the site's affiliate tag
 * (a shop's partner programme): omnitrade/amazon writes it with the
 * Associates tag, omnitrade/web with the query parameters configured per
 * host. Result: the URL, or null when the provider has no programme for
 * that product (use the plain address).
 *
 * @extends Request<?string>
 */
final class AffiliateLink extends Request
{
    public readonly Reference $reference;

    public function __construct(Reference|string $reference, public readonly ?string $tag = null)
    {
        $this->reference = Reference::of($reference);
    }

    public function getUrl(): ?string
    {
        return $this->getResult();
    }
}

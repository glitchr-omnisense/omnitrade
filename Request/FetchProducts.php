<?php

namespace Omnitrade\Request;

use Omnitrade\Model\ProductPage;

/**
 * A page of the catalogue: from the cursor the previous page gave (null: the
 * first), only what changed since $updatedSince when given, only what
 * matches $query when given (the platform's own search). Result: a ProductPage.
 *
 * @extends Request<ProductPage>
 */
final class FetchProducts extends Request
{
    public function __construct(
        public readonly ?string $cursor = null,
        public readonly ?\DateTimeInterface $updatedSince = null,
        public readonly ?string $query = null,
        /** At most this many per page; a provider may give fewer. */
        public readonly int $limit = 50,
    ) {
    }

    public function getPage(): ProductPage
    {
        return $this->getResult() ?? new ProductPage();
    }
}

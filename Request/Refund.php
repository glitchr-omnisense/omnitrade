<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Money;
use Omnitrade\Model\Refund as RefundModel;

/**
 * Give money back on a transaction, all of it or $amount. Result: the Refund.
 *
 * @extends Request<RefundModel>
 */
final class Refund extends Request
{
    public function __construct(
        public readonly string $reference,
        public readonly ?Money $amount = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function getRefund(): ?RefundModel
    {
        return $this->getResult();
    }
}

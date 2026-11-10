<?php

namespace Omnitrade;

use Omnisense\Contracts\Provider\ProviderFactoryInterface;

/** Builds a provider's gateway from its options (credentials, sandbox...): an omnisense/contracts provider factory. */
interface GatewayFactoryInterface extends ProviderFactoryInterface
{
    /** The name gateways are configured with: "stripe", "paypal", "shopify"... */
    public function getName(): string;

    /** @param array<string, mixed> $options */
    public function create(array $options = []): GatewayInterface;
}

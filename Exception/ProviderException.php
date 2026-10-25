<?php

namespace Omnitrade\Exception;

/**
 * The provider refused, or could not be reached: any HTTP error status (401,
 * 403, 404, 429, 5xx), a transport failure, an answer that cannot be read.
 * The family's rule (docs/providers.md): a provider throws this, never its HTTP
 * client's or its library's exception.
 */
class ProviderException extends \RuntimeException implements OmnitradeException
{
    public function __construct(
        public readonly string $provider,
        string $message,
        /** The provider's own error code, when it gave one */
        public readonly ?string $providerCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('[%s] %s', $provider, $message), 0, $previous);
    }
}

<?php

namespace Omnitrade\Exception;

/**
 * The provider answered 429 Too Many Requests: it refused the call, a
 * ProviderException as every refusal (the family's rule), with the seconds to
 * wait when the provider said ($retryAfter, from Retry-After or RateLimit).
 * The provider's next calls wait as long (UsageLimitReachedException before
 * they are sent). Nothing retries it: a payment is never sent twice by
 * Omnitrade. Stripe and Shopify refuse a throttled request before running
 * it; a create retried by the application with the same idempotency key
 * (Stripe's Idempotency-Key, PayPal's PayPal-Request-Id) happens once.
 */
final class RateLimitedException extends ProviderException
{
    public function __construct(
        string $provider,
        string $message,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($provider, $message, 'rate_limited', $previous);
    }
}

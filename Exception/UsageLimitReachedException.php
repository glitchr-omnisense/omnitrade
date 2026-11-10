<?php

namespace Omnitrade\Exception;

use Omnisense\Contracts\Policy\UsageLimitReached;

/**
 * A call refused before anything was sent to the provider: a rate limit of
 * its usage policy is reached (as the site runs it), or the provider asked to
 * wait and the wait is not over (a 429's Retry-After, a RateLimit field with
 * nothing left). Nothing was created, nothing was charged: the same call, with
 * the same idempotency key, may be made again after $retryAfter seconds.
 */
final class UsageLimitReachedException extends UsageLimitReached implements OmnitradeException
{
}

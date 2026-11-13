---
title: Usage policies and rate limits
order: 37
---

# Usage policies and rate limits

Omnitrade is built on [omnisense/contracts](https://github.com/glitchr-omnisense/contracts). Each
provider package declares its **usage policy** as data: rate limits, rights, requirements, and
where they were read. The file is `policy.json` in the package, named by `extra.omnisense.policy`
in its `composer.json`, in the schema of `omnisense/contracts`
(`resources/usage-policy.schema.json`). Run `vendor/bin/omnisense-policies` to print every
installed one.

| Package | Rate limits declared | The real rule, in the policy's notes |
|---|---|---|
| omnitrade/stripe | 25 a second (every call), 5 a second (`createAccount`) | 100 a second live, 25 in a sandbox, 25 per endpoint; Connect account creations 30 live, 5 in a sandbox; reads at most 500 per transaction over 30 days |
| omnitrade/paypal | unknown (`null`) | PayPal does not publish its limits (429 `RATE_LIMIT_REACHED`) |
| omnitrade/shopify | 2 a second, per key | cost-based: a bucket of query points restored at 100 a second on the standard plans (up to 2,000 on Commerce Components), 1,000 points at most per query. The schema cannot express it, so the stricter form is declared |
| omnitrade/woocommerce | none (`[]`) | the REST API has no limit of its own; the shop's host may set one |

## Reading and tightening a policy

```php
$registry = new Registry($factories, [
    'card' => [
        'factory' => 'stripe',
        'options' => ['api_key' => '...'],
        'policy' => ['rateLimits' => [['max' => 10, 'window' => 'second', 'operations' => ['purchase']]]],
    ],
], $sharedLimiter);

$registry->policy('card');      // Stripe's, tightened by the site's
$registry->policies();          // every gateway's: a "terms of the services we use" page
$registry->get('card')->capabilities()->contracts();   // the operations it supports
```

A site may only tighten a policy. A looser limit is an `InvalidPolicyException` from
omnisense/contracts. An operation is `GatewayInterface`'s method name (`purchase`, `refund`,
`fetchProducts`...), plus `registerWebhook`, `fetchWebhooks` and `deleteWebhook`
(`Gateway::OPERATIONS`).

## The guard

`Registry::get()` gives the gateway as before. `Registry::guarded($name)` gives it behind its
policy: before a request is sent, each limit counting its operation is checked. A limit reached
refuses the request with `UsageLimitReachedException`, and nothing is sent. The request is
counted after, answered or failed. A webhook (`notify()`) is never refused by the guard.

The limiter counts in memory by default. An application served by many processes gives the
registry a shared one (its third argument; in Symfony, `omnitrade.limiter`): any
`Omnisense\Contracts\Policy\RateLimiterInterface`. One over `symfony/rate-limiter` is planned for
omnibridge/symfony and is not released yet.

## A 429, and a payment never made twice

Whether the gateway is guarded or not, each provider's API client honours the waits the provider
asks for:

- **A 429** is a `RateLimitedException`. It is a `ProviderException`, so existing catches still hold
  it, with `providerCode` `rate_limited` and `retryAfter` in seconds when the provider said
  (`Retry-After`, or the `RateLimit` field). For Stripe this covers both rate limits and
  `lock_timeout`, and Stripe processes neither.
- **The next calls** to that provider wait as long. They are refused with
  `UsageLimitReachedException` before anything is sent: in every process when the limiter is
  shared.
- **Nothing retries a call**: neither the guard, the wait, nor the API clients. A create refused is
  the caller's to make again, later, **with the same idempotency key**:
  - Stripe: `Idempotency-Key` on Checkout Sessions, subscriptions, refunds and connected accounts
    (`Payment::$idempotencyKey`, `refund(..., $idempotencyKey)`, `CreateAccount::$idempotencyKey`).
  - PayPal: `PayPal-Request-Id` on order creation, authorization, capture and refund.
  - Shopify documents no idempotency for `draftOrderCreate`, so a mutation is never sent twice. A
    5xx may come after it ran: it is a `ProviderException`, and the draft order may exist. A 429 or
    `THROTTLED` comes before it runs: it is a `RateLimitedException`. Queries are retried as
    before (`retry_delay`).
  - WooCommerce has no idempotency key: an order created twice is two orders.
  - Without a key, nothing makes a retry safe. Give one per attempt and keep it with the attempt.
- **Nor the HTTP client.** The client a factory is given (the bundle's `http_client`) is kept with
  its retries off (omnisense/contracts' `Http\NoRetry`): Symfony's `RetryableHttpClient`
  (`framework.http_client`'s `retry_failed`) would send a payment, a capture, a refund or an order
  again on a 429, 502 or 503, and with WooCommerce or Shopify that is a second order. A test
  client (`MockHttpClient`) is kept as given.

```php
try {
    $transaction = $registry->get('card')->purchase($payment);   // $payment->idempotencyKey: the attempt's
} catch (RateLimitedException|UsageLimitReachedException $e) {
    // Nothing was charged: try again in $e->retryAfter seconds, with the same Payment.
}
```

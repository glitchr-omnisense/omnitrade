---
title: Errors
order: 50
---

## Errors

Every exception Omnitrade throws implements `Omnitrade\Exception\OmnitradeException` (an interface
extending `\Throwable`):

| Exception | Extends | When |
|---|---|---|
| `InvalidConfigException` | `\LogicException` | a gateway not configured, a factory not installed, a required option missing or empty, an API client of the wrong class, Amazon's unknown `credential_version` |
| `RequestNotSupportedException` | `\LogicException` | the provider does not do that (no authorizations at Shopify): `supports()` says so beforehand; thrown before any call |
| `ProviderException` | `\RuntimeException` | the provider refused, or could not be reached: any HTTP error status, a transport failure, an answer that cannot be read, a request the provider package refuses before calling (a Checkout session without `returnUrl`) |
| `InvalidNotificationException` | `ProviderException` | a webhook whose signature does not hold, that is unsigned or too old, or that cannot be checked for want of a secret |

The first two are mistakes of the application (`\LogicException`): fix the configuration or ask
`supports()` first. The last two happen at run time: catch them where the application talks to
the provider.

```php
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Exception\RequestNotSupportedException;
use Omnitrade\Request\Refund;

if (!$gateway->supports(Refund::class)) {
    return 'Refund it from the platform\'s own admin.';
}
try {
    $refund = $gateway->refund($reference, Money::of(500, 'EUR'), idempotencyKey: $attempt);
} catch (ProviderException $e) {
    $logger->warning('Refund refused', ['provider' => $e->provider, 'code' => $e->providerCode, 'message' => $e->getMessage()]);
}
```

### ProviderException

```php
new ProviderException(string $provider, string $message, ?string $providerCode = null, ?\Throwable $previous = null)
```

| Member | |
|---|---|
| `string $provider` | the gateway's name (`stripe`, `paypal`) |
| `?string $providerCode` | the provider's own error code, when it gave one |
| `getMessage()` | `[<provider>] <message>`: the provider's own message when it gave one |
| `getPrevious()` | the HTTP client's exception, for a transport failure |

There is no exception per status: a 401 is a `ProviderException` like a 500, its message and
`providerCode` saying which. What each provider puts there:

| Provider | `message` | `providerCode` |
|---|---|---|
| Stripe | `error.message` | `error.code` (`resource_missing`) |
| PayPal | `details[0].description`, else `message`, else `error_description` | `name` (`RESOURCE_NOT_FOUND`), else `details[0].issue`, else `error` |
| Shopify | `Shopify returned HTTP 401.`, the GraphQL `errors` or a mutation's `userErrors` (`field: message; ...`) | none; `ShopifyApiException::$errors` keeps Shopify's error list |
| WooCommerce | `message` | `code` (`woocommerce_rest_shop_order_invalid_id`) |
| Web | `The page answered HTTP 404.`, `Not a web address`, `... is on the local network.` | the HTTP status, `invalid_url`, `private_address` |
| Amazon | `errors[0].message` (or `Message`) | `errors[0].code`, else the HTTP status; `no_credentials`, `query_required` |

A body that is not JSON (an HTML error page from a proxy) is `... answered HTTP <status> with a
body that is not JSON.`; a transport failure (DNS, connection, timeout) is `... request failed:
<the HTTP client's message>`, that exception as `previous`. `Omnitrade\Shopify\Api\ShopifyApiException`
is Shopify's `ProviderException`, with the errors Shopify gave in `$errors`.

`Gateway::execute()` also throws a `ProviderException` when an action returns without answering
(`<action class> left the request unanswered.`): a provider package's bug.

### Real messages

From a script run on 2026-10-25 in the harness image, on a registry with a PayPal gateway, one
missing its secret, a Shopify gateway, one naming a factory not installed, and PayPal answering
HTTP 404:

```
get("card")
  InvalidConfigException: No "card" gateway; configured: paypal, broken, shop, other.
get("other")
  InvalidConfigException: No "stripe" factory for the "other" gateway; installed: paypal, shopify.
get("broken")
  InvalidConfigException: The "paypal" gateway needs: secret.
shop->authorize()
  RequestNotSupportedException: The "shopify" gateway does not support Authorize.
paypal->fetch()
  ProviderException: [paypal] Specified resource ID does not exist. (provider paypal, providerCode 'RESOURCE_NOT_FOUND')
paypal->notify()
  InvalidNotificationException: [paypal] No webhook_id: the notification cannot be checked. (provider paypal, providerCode NULL)
new Payment(fee, no destination)
  InvalidArgumentException: An application fee is kept on a payment sent to a destination account.
```

### Not found

`fetchProduct()` of a product the provider does not have answers `null`, not an exception (Stripe's
`resource_missing`, WooCommerce's `invalid_id`, Shopify's empty `product`, an Amazon `getItems`
with no item, a page with no product markup). `fetchOrder()` of an unknown Shopify order is a
`ProviderException` (`Shopify knows no order ...`); `fetch()` of a deleted Shopify draft order is a
CANCELLED transaction. Elsewhere an unknown reference is the provider's 404, a
`ProviderException`.

### The models' own checks

Models and requests check their arguments with PHP's `\InvalidArgumentException`, which is not an
`OmnitradeException`: it is thrown by the constructor, before any gateway is involved.

| Thrown by | When |
|---|---|
| `Payment` | an `applicationFee` without a `destination`; one in another currency, negative or larger than the amount |
| `Reference` (and `FetchProduct`, `AffiliateLink`, which build one) | neither an id nor a URL |
| `Subscribe` | an interval other than `day`, `week`, `month`, `year`; an interval count under 1 |

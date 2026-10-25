---
title: Verified, and not
order: 90
---

# Verified, and not

## What the tests prove

145 tests, green on 2026-10-25 in the workspace (the core and the six providers as path
repositories, PHP 8.4.26, PHPUnit 11.5.57, run in the harness image with no network: 1244
assertions). All of them offline, on answers recorded in the shape each service documents.

| Package | Tests | |
|---|---|---|
| `omniscient/omnitrade` | 13 | the gateway answers through the supporting action and refuses the rest naming itself, required options, the registry builds each gateway once, `Money`'s minor units, `Status`; `Reference` and the catalogue requests; the Symfony bundle (gateways built from configuration, injected by name, an application's factory autoconfigured); `Tests/BareTest.php`: the registry built by hand, a product read and a payment asked with no class of a framework loaded, and the bundle loading the framework |
| `omnitrade/stripe` | 33 | the conformance suite (12); Checkout purchase, fetch, refund with its idempotency key, signed webhooks, payment methods; Connect (Express account, destination charge and fee, the fee's checks, Stripe's errors), subscriptions (Checkout, read, cancel, portal, the webhook's account and subscription); the catalogue (pages, `updatedSince` among each page, search, a variant per price, recurring prices, no stock, product and price events) |
| `omnitrade/paypal` | 15 | the conformance suite on an order authorized then captured or voided; an order approved then captured; a refund on the order's capture; a webhook verified by PayPal |
| `omnitrade/shopify` | 24 | the conformance suite on Admin GraphQL answers; a draft order paid on its invoice, fetched as what became of it; a shop order; a webhook's HMAC and shop; the catalogue (pages, mapping, the `currency` option, finding by id, bare id and page, inventory, REST and GraphQL shapes giving the same product, untracked stock, product and inventory webhooks) |
| `omnitrade/woocommerce` | 23 | the conformance suite on REST v3 answers; an unpaid order paid on the shop's checkout, fetched by status, a refund and the payment methods, a signed webhook; the catalogue (pages, published only, simple and variable products, finding by id, slug or variation, inventory, product webhooks) |
| `omnitrade/web` | 21 | the conformance suite on a recorded product page; JSON-LD in a graph, a ProductGroup and its variants, microdata, OpenGraph, no product, the local network refused, affiliate links, prices in every writing |
| `omnitrade/amazon` | 16 | the conformance suite on Creators API answers (credentials given); a product by its page, a search, the link alone without credentials, ASINs in addresses |

The conformance suite (`Omnitrade\Testing\GatewayContractTests`, [Testing](testing.md#the-conformance-suite))
runs in each of the six providers; it found and fixed, per the providers' commits: omnipay's network
failures escaping Stripe as omnipay's exception, a voided PayPal authorization read as AUTHORIZED,
and Shopify's 429 retried after a fixed backoff whatever `Retry-After` said.

The examples of this manual were run on 2026-10-25 in the harness image (`omniscient/omnitrade-harness`,
PHP 8.4) on the workspace's checkouts, with `MockHttpClient` answers: [installation](installation.md),
[payments](usage-payments.md), [catalogue](catalogue.md), [testing](testing.md),
[writing a provider](providers.md), the capability tables of the [index](index.md#providers), the
[Symfony](symfony.md) configuration tree and services, the error messages of [Errors](errors.md).

## Not verified

- No call was made to a live service or a sandbox: no recorded answer here was captured from a
  real account. The [harness](harness.md) is where test keys exercise them; no run with keys is
  recorded in the packages' history.
- Connected accounts and subscriptions are checked by the suite only for "supported or not" and
  their errors; their answers by Stripe's own tests, on recorded answers.
- Amazon's Creators API: the tests follow its documentation as read on 2026-10-04; the field
  names of `externalIds` and `availability.type` are assumed from PA-API 5 (omnitrade/amazon's
  manual).
- The [Laravel](laravel.md) service provider was written from the registry's API, not run in a
  Laravel application.

## Known defects

What the code does today, where it differs from what one would expect from the contract:

- `omnitrade/stripe` sends the lines without `Payment::$shipping` and `::$discount` when the lines
  add up with them (`Action/PurchaseAction.php`, `lines()`): the Checkout session is for the lines'
  total, not the payment's amount ([payments](usage-payments.md#a-complete-payment-from-the-order-to-the-refund)).
- `omnitrade/paypal` asks for an AUTHORIZE order's authorization only inside `capture()`
  (`Action/CaptureAction.php`): after `authorize()` and the buyer's approval, `fetch()` reads the
  order PENDING (not AUTHORIZED) and `void()` throws `The order holds no authorization to void.`
  (`Action/VoidAction.php`).
- `omnitrade/stripe`'s `refund()` without an amount throws omnipay's `InvalidRequestException`
  before any call (`Action/RefundAction.php` sets the amount only when given; omnipay's refund
  requires it; `Api::sendOmnipay()` catches only omnipay's HTTP exceptions): neither a full refund
  nor the family's `ProviderException`. The conformance suite always refunds an amount, so it does
  not see it.
- `omnitrade/stripe` reads a refund from the session's expanded `payment_intent`
  (`Sessions.php`), which `fetch()` never asks Stripe to expand: a refunded session fetches PAID.
- `omnitrade/web` checks the final address after redirects only once the page was read
  (`Api.php`, `page()`): a redirect to a private address is requested before it is refused.
- `Product::$description` is HTML by the contract; `omnitrade/amazon` gives the features as plain
  text lines and `omnitrade/web` strips the tags.
- No provider reads `paymentMethods()`'s amount and country.

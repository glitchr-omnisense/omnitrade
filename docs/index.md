# omnisense/omnitrade

Omnitrade is one PHP contract for payment providers and commerce platforms. A shop asks every
provider the same questions - take this payment, reserve it, capture it, give money back, where
does this payment stand, what is this webhook telling me, what is in this shop's catalogue, how
many are left - and gets the same answers back: `Transaction`, `Refund`, `Notification`,
`PlatformOrder`, `Product`, `Stock`. Each provider is a package of its own (`omnitrade/stripe`,
`omnitrade/paypal`, `omnitrade/shopify`...) that turns those questions into the service's API
calls.

```php
$gateway = $registry->get('card');

$transaction = $gateway->purchase($payment);   // PAID, or PENDING with the page to send the buyer to
$gateway->fetch($transaction->reference);      // where it stands now
$gateway->refund($transaction->reference, Money::of(500, 'EUR'), idempotencyKey: 'refund-42');
$gateway->notify($request->getContent(), $request->headers->all());   // a webhook, checked and read

$page = $gateway->fetchProducts(updatedSince: $lastSync);   // the platform's catalogue, page by page
$gateway->fetchProduct('https://cave.example/products/margaux-2019');   // by id or by its page
$gateway->fetchInventory();                                  // stock levels, where the platform counts them

$gateway->affiliateLink('https://www.amazon.fr/dp/B0C1234567');   // the product's address with the site's tag

$account = $gateway->createAccount('FR', 'host@example.org');   // a connected account (Stripe Connect, Express)
$gateway->accountLink($account->reference, $return, $refresh);  // where its holder completes it
$gateway->subscribe($payment, 'month');                         // a subscription, on the provider's page
```

The design is Omnibus's, itself Payum's: a provider's **factory** fills a configuration with its
name, its options, its API client and one **action** per request it answers; the **gateway** it
builds runs the action that supports each request; a **registry** holds the shop's gateways by
name. A provider that does not do something (no authorizations at Shopify) has no action for it:
`supports()` says so beforehand, and asking anyway throws `RequestNotSupportedException` before any
call. What every provider answers is kept whole in each model's `$raw`: normalization loses
nothing.

The core needs no framework: it requires `symfony/http-client-contracts` and nothing else. Each
provider requires `symfony/http-client`, a library that stands alone. A Symfony bundle is included
for applications that want the wiring done ([Symfony](symfony.md)).

Published as `omnisense/omnitrade`, formerly `glitchr/omnitrade`: the package `replace`s the old
name, so a `composer.json` that still requires `glitchr/omnitrade` resolves to this package and
never installs both. `omnisense/` is the vendor of every family's core; the providers keep the
`omnitrade/` vendor, the namespace is `Omnitrade\` (`Omnitrade\<Provider>\` for a provider) and
the GitHub repositories keep their names.

## When to use it

- A shop that takes payments through a hosted page (Stripe Checkout, PayPal's approval page, a
  Shopify invoice, a WooCommerce checkout) and wants to change provider, or offer two, without
  rewriting its checkout, its webhook endpoint and its order screens.
- A site that copies the catalogue of a platform it does not own (Shopify, WooCommerce, Stripe
  Products) into its own tables, or reads any shop's product page, and writes affiliate links.
- A platform that takes payments for others (a gift fund, a marketplace) through Stripe Connect,
  or sells subscriptions through Stripe Billing.

It is not a payment service of its own (no account, no money held, no card data: the buyer pays on
the provider's page) and it stores nothing: keeping the transaction's reference, the webhook's
event id and the catalogue's copy is the application's job. It does not take card numbers: no
provider here has a server-side card request.

## Providers

Six packages. The capabilities are what each gateway's `supports()` answers with its required
options set (probed on 2026-10-25 in the family's harness image, PHP 8.4, on a `MockHttpClient`:
`supports()` makes no call).

Payments:

| Package | Factory name | Purchase | Authorize, capture, void | Refund | Fetch | Payment methods | Notify |
|---|---|---|---|---|---|---|---|
| [`omnitrade/stripe`](https://github.com/glitchr-omnisense/omnitrade-stripe) | `stripe` | yes (Checkout) | - | yes | yes | yes (configured list) | yes |
| [`omnitrade/paypal`](https://github.com/glitchr-omnisense/omnitrade-paypal) | `paypal` | yes (Orders v2) | yes | yes | yes | yes (fixed list) | yes (verified by PayPal) |
| [`omnitrade/shopify`](https://github.com/glitchr-omnisense/omnitrade-shopify) | `shopify` | yes (draft order invoice) | - | - | yes | - | yes |
| [`omnitrade/woocommerce`](https://github.com/glitchr-omnisense/omnitrade-woocommerce) | `woocommerce` | yes (unpaid order) | - | yes | yes | yes (the shop's gateways) | yes |
| [`omnitrade/web`](https://github.com/glitchr-omnisense/omnitrade-web) | `web` | - | - | - | - | - | - |
| [`omnitrade/amazon`](https://github.com/glitchr-omnisense/omnitrade-amazon) | `amazon` | - | - | - | - | - | - |

Platform orders, catalogue, affiliate links, connected accounts, subscriptions:

| Package | Fetch order | Fetch products | Fetch product | Inventory | Affiliate link | Connected accounts | Subscriptions |
|---|---|---|---|---|---|---|---|
| `omnitrade/stripe` | - | yes (Products and Prices) | yes (by id) | - | - | yes (Express) | yes (Checkout, portal) |
| `omnitrade/paypal` | - | - | - | - | - | - | - |
| `omnitrade/shopify` | yes | yes | yes (id, handle, page) | yes | - | - | - |
| `omnitrade/woocommerce` | yes | yes | yes (id, slug, page) | yes | - | - | - |
| `omnitrade/web` | - | - | yes (any page's markup) | - | yes (rules by host) | - | - |
| `omnitrade/amazon` | - | with `client_id` and `client_secret` (a search) | with `client_id` and `client_secret` | - | yes (partner tag) | - | - |

"Connected accounts" are `CreateAccount`, `AccountLink` and `FetchAccount`; "Subscriptions" are
`Subscribe`, `FetchSubscription`, `CancelSubscription` and `SubscriptionPortal`. Each provider's
own manual lists its options, credentials and behaviour.

## How this manual is organised

- [Installation](installation.md): requirements, a first script in plain PHP that runs as it is.
- [Symfony](symfony.md) and [Laravel](laravel.md): wiring the registry in a framework.
- [Concepts](concepts.md): gateways, factories, the registry, requests and actions, every model
  and every field.
- Usage: [payments](usage-payments.md) (purchase, authorize, capture, void, refund, fetch,
  payment methods), [webhooks](usage-webhooks.md), [the catalogue and affiliate
  links](catalogue.md), [connected accounts](connect.md), [subscriptions](subscriptions.md).
- [Configuration reference](configuration.md): the registry's configuration and every
  provider's options, and which `Payment` fields each provider reads.
- [Errors](errors.md): the four exceptions, when each is thrown, what to catch.
- [Testing](testing.md): testing an application that uses Omnitrade, and the conformance suite.
- [The Docker harness](harness.md): every provider installed, a console to call them.
- [Writing a provider](providers.md), the errors' rule and the conformance suite.
- [Verified, and not](verified.md).

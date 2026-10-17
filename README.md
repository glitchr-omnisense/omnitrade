# omniscient/omnitrade

One contract for payment providers and commerce platforms - the Omnibus of trade.

Published as `omniscient/omnitrade` (formerly `glitchr/omnitrade`, which this package replaces: a
`composer.json` that still requires the old name keeps resolving to this package and never installs
both). `omniscient/` is the vendor of every family's core, the providers keep `omnitrade/`, the
namespace `Omnitrade\` and the GitHub repositories do not change.

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

The catalogue (`Product`, `ProductVariant`, `Offer`, `Stock`, `Media`, `Option`, `Merchant`)
and the affiliate links are described in [docs/catalogue.md](docs/catalogue.md), connected
accounts and payments for someone else (`Payment::$destination`, `::$applicationFee`) in
[docs/connect.md](docs/connect.md), subscriptions in [docs/subscriptions.md](docs/subscriptions.md).

This package holds the contract (`GatewayInterface`, `GatewayFactory`, `Registry`), the models
(`Payment`, `Money`, `Transaction`, `Refund`, `PaymentMethod`, `Notification`, `PlatformOrder`,
`Product`, `ProductVariant`, `Offer`, `Stock`...),
the requests and a bridge for Symfony. It needs no framework: it requires nothing but
`symfony/http-client-contracts`, each provider package `symfony/http-client`. Each provider is a
package of its own:

| Package | Provider |
|---|---|
| `omnitrade/stripe` | Stripe: Checkout sessions, refunds, webhooks (on omnipay/stripe); its Products and Prices; Connect (Express accounts, destination charges); subscriptions |
| `omnitrade/paypal` | PayPal: orders, capture, refunds, webhooks (on omnipay/paypal) |
| `omnitrade/shopify` | Shopify: draft orders paid on the invoice page, orders, the catalogue and inventory, webhooks (Admin GraphQL) |
| `omnitrade/woocommerce` | WooCommerce: orders and their payment state, the catalogue and stock (REST API v3) |
| `omnitrade/web` | Any product page, read from its own markup (JSON-LD, microdata, OpenGraph); affiliate links by host |
| `omnitrade/amazon` | Amazon: products through the Creators API, affiliate links with the partner tag |

A provider's factory fills a `Config` - its name, options, API client and actions - and the
gateway runs the actions that support each request, exactly as an Omnibus carrier does. A
provider that does not do something (no authorizations at Shopify) throws
`RequestNotSupportedException`; `supports()` says so beforehand.

What every provider answers with is kept whole in `$raw`: normalization loses nothing.

## Plain PHP

```sh
composer require omniscient/omnitrade omnitrade/paypal
```

```php
require __DIR__.'/vendor/autoload.php';

use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new PayPalGatewayFactory(HttpClient::create())], [
    'paypal' => ['factory' => 'paypal', 'options' => ['client_id' => '...', 'secret' => '...', 'sandbox' => true]],
]);

$transaction = $registry->get('paypal')->purchase(new Payment(amount: Money::of(1250, 'EUR'), reference: 'ORDER-1042', returnUrl: 'https://shop.example/retour'));
$transaction->isRedirect() && header('Location: '.$transaction->redirectUrl);   // PayPal's page; capture() when the buyer is back
```

A factory takes the HTTP client to call with - the application's, a `MockHttpClient` in a test -
and makes its own when given none. A whole script that runs as it is, and the rest:
[docs/installation.md](docs/installation.md). No class of a framework is loaded on the way:
`Tests/BareTest.php` checks it in a process of its own, and so does
`docker compose run --rm omnitrade bare` ([docs/harness.md](docs/harness.md)).

## Symfony

In a Symfony application a bundle does the wiring; its components (`symfony/config`,
`symfony/dependency-injection`, `symfony/http-kernel`) are not required by this package: the
application has them ([docs/symfony.md](docs/symfony.md)).
`Omnitrade\Bridge\Symfony\OmnitradeBundle`: every `omnitrade/*` provider installed registered,
`Omnitrade\Registry` autowired, and each configured gateway injectable by its name.

```yaml
omnitrade:
    gateways:
        card:   { factory: stripe, options: { api_key: '%env(STRIPE_API_KEY)%', webhook_secret: '%env(STRIPE_WEBHOOK_SECRET)%' } }
        paypal: { factory: paypal, options: { client_id: '%env(PAYPAL_CLIENT_ID)%', secret: '%env(PAYPAL_SECRET)%', sandbox: true } }
```

```php
public function __construct(GatewayInterface $card) {}
```

An application's own `GatewayFactoryInterface` is registered too (autoconfigured).

## Docker: every provider with your test keys

`docker/` runs this package with every `omnitrade/*` provider installed - from GitHub, or from the
checkouts beside this one when `OMNITRADE_PLUGINS=../..` is set - and a console that exercises
them with the keys in `docker/.env` (copy `.env.dist`; `docker compose run --rm omnitrade gateways`
says which providers are configured and what each one does):

```sh
cd docker && cp .env.dist .env
docker compose run --rm omnitrade gateways
docker compose run --rm omnitrade purchase stripe --amount 1990      # prints the Checkout page to open
docker compose run --rm omnitrade fetch stripe cs_test_...
docker compose run --rm omnitrade refund stripe cs_test_... --amount 500
docker compose run --rm omnitrade notify stripe -H 'Stripe-Signature: t=...,v1=...' < event.json
docker compose run --rm omnitrade catalogue shopify --since='-1 day' --limit=20   # products, variants, prices, stock
docker compose run --rm omnitrade catalogue woocommerce --query=margaux --cursor=2
docker compose run --rm omnitrade catalogue shopify --product=https://shop.example/products/margaux --inventory
docker compose run --rm omnitrade catalogue woocommerce --inventory                # every variant's stock
docker compose run --rm omnitrade bare --recorded                    # plain PHP: no bundle, no container, and what PHP loaded
docker compose run --rm omnitrade test                               # every package's tests
```

## Documentation

- [Installation and first calls](docs/installation.md): plain PHP first
- [Symfony](docs/symfony.md)
- [The catalogue and affiliate links](docs/catalogue.md)
- [Connected accounts](docs/connect.md)
- [Subscriptions](docs/subscriptions.md)
- [The Docker harness](docs/harness.md)

## Responsibility

`omniscient/omnitrade` and its `omnitrade/*` providers are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (merchant or partner agreements, keys, rate limits, mentions such as the affiliate one), named
with a link in the "Responsibility" section of the README of each provider that reaches one. The MIT
licence covers this code only and grants no right to any service or to its data.

Whoever installs and runs these packages is responsible for complying with those terms, for
obtaining whatever they require, and for any use made of what they return. The authors publish them
free of charge, make no commercial use of them, and accept no liability for how they are used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.

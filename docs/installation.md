---
title: Installation
order: 10
---

# Installation

```sh
composer require omnisense/omnitrade omnitrade/paypal            # one provider
composer require omnitrade/stripe omnitrade/shopify omnitrade/woocommerce omnitrade/web omnitrade/amazon
```

## Requirements

- PHP 8.2 or later.
- `omnisense/omnitrade` requires `symfony/http-client-contracts` (^3.0) and nothing else.
- Each provider requires `omnisense/omnitrade` and `symfony/http-client` (^6.4, ^7.0 or ^8.0), a
  library that stands alone and loads no framework. Besides:

| Package | Also requires |
|---|---|
| `omnitrade/stripe` | `ext-bcmath`, `omnipay/stripe` (^3.2) and `omnipay/common` (^3.0), `nyholm/psr7`, `php-http/discovery`, `psr/http-client`, `psr/http-factory` |
| `omnitrade/paypal` | - |
| `omnitrade/shopify` | `psr/log` |
| `omnitrade/woocommerce` | - |
| `omnitrade/web` | `ext-dom`, `ext-libxml` |
| `omnitrade/amazon` | - |

Omnitrade needs no framework. It runs the same in plain PHP, in a worker, in Laravel or Slim,
and in Symfony, where a bundle does the wiring ([Symfony](symfony.md)).

`omnitrade/stripe` stands on [Omnipay](https://omnipay.thephpleague.com) (`omnipay/stripe`), a
library with no framework either; Omnipay's own requirements come with it, `symfony/http-foundation`
among them - used as a library for its request object: no kernel, no container.

## Plain PHP

```php
<?php // bare.php

require __DIR__.'/vendor/autoload.php';

use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Registry;
use Omnitrade\Request\Authorize;
use Omnitrade\Request\FetchProducts;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// The HTTP client the providers call with: in an application, HttpClient::create(). Here, one that
// answers as PayPal's sandbox does (its documentation's example order), so that the script runs as
// it is, without an account.
$http = new MockHttpClient(static fn (string $method, string $url) => new MockResponse(json_encode(match (parse_url($url, PHP_URL_PATH)) {
    '/v1/oauth2/token' => ['access_token' => 'paypal_test_not_a_real_token', 'expires_in' => 32400],
    '/v2/checkout/orders' => ['id' => '5O190127TN364715T', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
        ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T'],
    ]],
})));

$registry = new Registry([new PayPalGatewayFactory($http)], [
    'paypal' => ['factory' => 'paypal', 'options' => ['client_id' => 'paypal_test_not_a_real_client_id', 'secret' => 'paypal_test_not_a_real_secret', 'sandbox' => true]],
]);
$gateway = $registry->get('paypal');

var_dump($gateway->supports(Authorize::class), $gateway->supports(FetchProducts::class));   // what it does, said beforehand

$transaction = $gateway->purchase(new Payment(
    amount: Money::of(1250, 'EUR'),                       // minor units
    reference: 'ORDER-1042',
    description: 'Commande n° 1042',
    customer: new Customer(email: 'camille@example.org', name: 'Camille Durand', country: 'FR'),
    lines: [new Line('Dix heures', Money::of(1250, 'EUR'))],
    returnUrl: 'https://shop.example/retour',
    cancelUrl: 'https://shop.example/panier',
    idempotencyKey: 'attempt-1',
));

echo $transaction->provider, ' ', $transaction->reference, ' ', $transaction->status->value, "\n";
if ($transaction->isRedirect()) {
    echo 'send the buyer to ', $transaction->redirectUrl, "\n";   // then capture() when they are back
}
```

```
$ php bare.php
bool(true)
bool(false)
paypal 5O190127TN364715T pending
send the buyer to https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T
```

(first run on 2026-10-05 in an empty directory, after `composer require omnisense/omnitrade
omnitrade/paypal`; run again on 2026-10-25 in the family's harness image, PHP 8.4, with the same
output. Every provider asks for an account's keys, so the script answers in PayPal's place, with
the order id of PayPal's own documentation. With `HttpClient::create()` and a sandbox
application's client id and secret, the same lines create a real sandbox order.)

That is all there is to it:

- a **factory** per provider package (`StripeGatewayFactory`, `PayPalGatewayFactory`,
  `ShopifyGatewayFactory`, `WooCommerceGatewayFactory`, `WebGatewayFactory`,
  `AmazonGatewayFactory`), which takes the HTTP client to call with - the application's, a
  `MockHttpClient` in a test; with none given it makes its own (`HttpClient::create()`);
- the **registry**, built by hand from the factories and the gateways' options, by name: `get()`,
  and `create($name, $overrides)` for keys typed in a back office;
- the **gateways** it gives - `purchase()`, `authorize()`, `capture()`, `refund()`, `void()`,
  `fetch()`, `notify()`, `fetchProducts()`, `fetchProduct()`, `fetchInventory()`,
  `affiliateLink()`... - the same for every provider; `supports()` says beforehand what one does.

Building a gateway calls nothing: a token (PayPal's, Amazon's) is asked for on the first call that
needs it, and a missing required option is reported by `create()` - or by `Registry::get()` the
first time the gateway is asked for - as an `InvalidConfigException` naming it
(`The "paypal" gateway needs: secret.`).

No class of a framework is loaded on the way - a test of this package checks it in a process of
its own (`Tests/BareTest.php`), and so does `docker compose run --rm omnitrade bare`
([harness](harness.md)).

## After the buyer is back

```php
$paid = $gateway->capture($transaction->reference, idempotencyKey: 'attempt-1');   // PayPal: the money is taken now
$paid->isPaid();

$gateway->fetch($transaction->reference);                                          // where it stands now
$gateway->refund($transaction->reference, Money::of(500, 'EUR'), idempotencyKey: 'refund-42');
```

What each provider does on the way back differs (Stripe takes the money on its page, PayPal on
`capture()`, Shopify and WooCommerce bring nobody back): [payments](usage-payments.md).

## A webhook

```php
use Omnitrade\Exception\InvalidNotificationException;

try {
    $notification = $gateway->notify(file_get_contents('php://input'), getallheaders());   // checked against its signature, then read
} catch (InvalidNotificationException) {
    http_response_code(400);
    exit;
}
```

More in [webhooks](usage-webhooks.md).

## A product page, no key

```php
use Omnitrade\Web\WebGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$web = (new WebGatewayFactory(HttpClient::create()))->create(['affiliate' => ['amazon.fr' => ['tag' => 'mysite-21']]]);

$product = $web->fetchProduct('https://shop.example/products/margaux-2019');   // from its JSON-LD, microdata or OpenGraph
$product?->title; $product?->price(); $product?->media;
$web->affiliateLink('https://www.amazon.fr/dp/B0C1234567');                    // the address with the site's tag
```

The catalogue of a platform (Shopify, WooCommerce, Stripe), page by page: [catalogue](catalogue.md).
Connected accounts: [connect](connect.md). Subscriptions: [subscriptions](subscriptions.md).

## In a framework

- **Symfony**: `Omnitrade\Bridge\Symfony\OmnitradeBundle` registers the factories on the
  application's `http_client`, builds the registry from `config/packages/omnitrade.yaml` and makes
  each gateway injectable by its name: see [Symfony](symfony.md). Its components
  (`symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`) are not required by
  this package: a Symfony application has them.
- **Laravel**: there is no bridge for Omnitrade; a service provider of the application builds the
  registry once: [Laravel](laravel.md).
- **Any other**: build the `Registry` once, where the framework builds its services (a service
  provider, a container definition), as the script above does.

What each exception means and when it is thrown: [Errors](errors.md).

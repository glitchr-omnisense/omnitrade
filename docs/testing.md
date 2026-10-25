---
title: Testing
order: 60
---

# Testing

Code that uses Omnitrade depends on `GatewayInterface` (or on the `Registry`), never on a
provider's classes: a test hands it a gateway that answers what the test needs. Two ways, both
offline:

- **a provider of the test's own**: a small `GatewayFactory` whose actions answer fixed
  transactions. The family has no ready-made fake gateway: a factory of a few lines does it, and
  `supports()`, `execute()` and the exceptions behave as with any provider;
- **a real provider on recorded answers**: the provider's factory built on Symfony's
  `MockHttpClient`, which answers each request as the service would. The provider's own mapping
  runs, so the test also proves the application reads what that provider gives.

## An application's test

`CheckoutTest.php` tests a `Checkout` service of the application both ways:

```php
<?php

namespace App\Tests;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Config;
use Omnitrade\GatewayFactory;
use Omnitrade\GatewayInterface;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Registry;
use Omnitrade\Request\FetchTransaction;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The application's code under test: where to send the buyer, or null when it is paid already. */
final class Checkout
{
    public function __construct(private readonly GatewayInterface $gateway)
    {
    }

    public function start(string $order, int $cents): ?string
    {
        $transaction = $this->gateway->purchase(new Payment(Money::of($cents, 'EUR'), $order, returnUrl: 'https://shop.example/back', idempotencyKey: $order.'-1'));

        return $transaction->isRedirect() ? $transaction->redirectUrl : null;
    }
}

/** A provider for the tests only: every purchase is a page to go to, every fetch is paid. */
final class FixedGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnitrade.factory_name' => 'fixed',
            'omnitrade.factory_title' => 'Fixed',
            'omnitrade.action.purchase' => new class implements ActionInterface {
                public function supports(Request $request): bool { return $request instanceof Purchase; }
                public function execute(Request $request): void
                {
                    $request->setResult(new Transaction('fixed', 'tx_'.$request->payment->reference, Status::PENDING, $request->payment->amount, 'https://pay.example/tx_'.$request->payment->reference));
                }
            },
            'omnitrade.action.fetch' => new class implements ActionInterface {
                public function supports(Request $request): bool { return $request instanceof FetchTransaction; }
                public function execute(Request $request): void { $request->setResult(new Transaction('fixed', $request->reference, Status::PAID)); }
            },
        ]);
    }
}

final class CheckoutTest extends TestCase
{
    public function testTheBuyerIsSentToTheProvidersPage(): void
    {
        $checkout = new Checkout((new FixedGatewayFactory())->create());

        self::assertSame('https://pay.example/tx_ORDER-7', $checkout->start('ORDER-7', 1250));
    }

    public function testWithPayPalsAnswersRecorded(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = $method.' '.parse_url($url, \PHP_URL_PATH);

            return new MockResponse(json_encode(match (parse_url($url, \PHP_URL_PATH)) {
                '/v1/oauth2/token' => ['access_token' => 'A21AA_test', 'expires_in' => 32400],
                '/v2/checkout/orders' => ['id' => '5O190127TN364715T', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T']]],
            }));
        });
        $registry = new Registry([new PayPalGatewayFactory($http)], ['paypal' => ['factory' => 'paypal', 'options' => ['client_id' => 'id', 'secret' => 'secret', 'sandbox' => true]]]);

        $url = (new Checkout($registry->get('paypal')))->start('ORDER-7', 1250);

        self::assertSame('https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T', $url);
        self::assertSame(['POST /v1/oauth2/token', 'POST /v2/checkout/orders'], $sent);
    }
}
```

```
$ vendor/bin/phpunit --testdox tests/CheckoutTest.php
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.26

Time: 00:00.057, Memory: 8.00 MB

Checkout (App\Tests\Checkout)
 ✔ The buyer is sent to the providers page
 ✔ With pay pals answers recorded

OK (2 tests, 3 assertions)
```

(run on 2026-10-25 in the harness image, with `phpunit/phpunit` 11.5 and `symfony/http-client`;
`MockHttpClient` is part of `symfony/http-client`, which every provider requires.)

What the recorded answers must look like is each provider's: the provider's own tests
(`Tests/*GatewayTest.php` and `Tests/*ContractTest.php` in each provider's repository) hold
answers shaped as each service documents them, and are a good place to copy from. The paths a
provider calls are in its manual.

- A webhook is tested the same way: sign the body as the provider does (Stripe's
  `t=<time>,v1=<hmac>`, Shopify's and WooCommerce's base64 HMAC, `Api::sign()` in
  `omnitrade/woocommerce` and `Hmac::sign()` in `omnitrade/shopify` compute it) and hand it to
  `notify()`; the [payments](usage-payments.md#a-complete-payment-from-the-order-to-the-refund)
  example signs a Stripe event. PayPal's are verified by PayPal: record the answer of
  `POST /v1/notifications/verify-webhook-signature`.
- In a Symfony application, replace the `http_client` service in the `test` environment with a
  `MockHttpClient` (`framework.http_client.mock_response_factory`), or configure a gateway on an
  application factory of the test's own: the bundle autoconfigures it ([Symfony](symfony.md#an-applications-own-provider)).
- `Registry::create($name, $overrides)` builds a gateway with other options for one test (a
  sandbox flag, a webhook secret) without touching the configured one.

## The conformance suite

`Omnitrade\Testing\GatewayContractTests` is the family's contract as PHPUnit tests, offline. It is
a trait: a provider's test case uses it, gives its factory, fake options and its service's
recorded answers, and gets twelve tests. Every provider of the family runs it
(`Tests/<Provider>ContractTest.php`); a provider of one's own should too
([Writing a provider](providers.md#a-complete-provider)). It requires `phpunit/phpunit` and
`symfony/http-client` in the provider's `require-dev`.

No test reaches the network: every request goes to the suite's `MockHttpClient`. A request with no
recorded answer is answered HTTP 404 and named in the failure message (`no recorded answer for:
...`).

### Hooks

| Method | Must or may | Default | |
|---|---|---|---|
| `factory(HttpClientInterface $http): GatewayFactoryInterface` | abstract | - | the provider's factory, built on the HTTP client the suite hands it |
| `options(): array` | abstract | - | options that build the gateway with everything it offers turned on (a webhook secret, optional credentials): fake values |
| `recordedAnswers(): array` | may | `[]` | the service's answers by `"METHOD /path"` (the query left out): a JSON body as an array (HTTP 200), a `MockResponse`, or a callable `(string $method, string $url, array $options): MockResponse` for an endpoint whose answer depends on the request (GraphQL) |
| `requiredOptions(): array` | may | the factory's `omnitrade.required_options` | the options that must be given |
| `payment(): Payment` | may | 12,50 EUR, `ORDER-1042`, a customer, one line, return and cancel URLs, idempotency key `attempt-1` | what `purchase()`, `authorize()` and `subscribe()` are asked with |
| `transactionReference(): string` | may | `contract-transaction` | the transaction (or platform order) `fetch()`, `capture()`, `refund()`, `void()` and `fetchOrder()` name |
| `refundReference(): string` | may | `transactionReference()` | the payment `refund()` gives money back on (a captured one, where an authorization alone has nothing to refund) |
| `productReference(): string` | may | `contract-product` | the product `fetchProduct()` and `affiliateLink()` name: an id there or a page's address |
| `catalogueQuery(): ?string` | may | `null` | the search `fetchProducts()` is asked with (Amazon needs one) |
| `signedNotification(): ?array` | needed when `notify()` is supported | `null` | a webhook as the provider signs it: `[body, headers]` |
| `tamperedNotification(): array` | may | the signed body with one more character | the same webhook tampered with (PayPal's: a signature PayPal's recorded verification refuses) |

### What each test checks

| Test | Checks |
|---|---|
| `testTheFactoryNamesTheGatewayItBuilds` | the factory's name is not empty and is the gateway's; the title is not empty; a `Registry` built from `{factory, options}` lists the factory and builds the gateway once |
| `testAMissingRequiredOptionIsAConfigurationError` | each required option left out, then set to `''`, is an `InvalidConfigException` (the first naming it); with none required, the gateway builds with no options |
| `testWhatItDoesNotSupportIsRefusedAsNotSupported` | every request `supports()` denies throws `RequestNotSupportedException` naming the gateway, and no HTTP request is made |
| `testWhatItSupportsFailsAsAProviderErrorWhenTheProviderDoes` | every request it grants, when the service answers HTTP 401, 403, 404, 429 (`Retry-After: 0`), 500, 503 with an HTML page, HTTP 200 with an HTML page, or cannot be reached, answers or throws a `ProviderException` naming the provider - never a `TypeError`, a `JsonException` or the HTTP client's exception; `fetchProduct()` on a 404 is not a product |
| `testAPurchaseIsATransactionInMinorUnits` | `purchase()` and `authorize()`, when supported, give a transaction naming the provider with a reference, of the payment's amount (when it gives one), PENDING, AUTHORIZED or PAID, `isRedirect()` when PENDING with a page |
| `testAFetchedTransactionKeepsItsInvariants` | `fetch()`: the provider's name, a reference, money in ISO currencies and not negative, no more refunded than paid, a redirect to an http(s) page |
| `testCaptureAndVoidAnswerTransactions` | `capture()` gives a transaction no longer AUTHORIZED, CANCELLED or EXPIRED; `void()` a CANCELLED one; neither supported: neither is `authorize()` |
| `testARefundIsRecordedInMinorUnits` | `refund()` of 5,00 EUR (key `refund-1`, reason `Overcharged`) records 500 EUR cents with the provider's refund id, and a refund's status |
| `testPaymentMethodsAreDescribed` | `paymentMethods(12,50 EUR, 'FR')`: a list of `PaymentMethod` with a code, a label, a kind among the constants, ISO currencies |
| `testASignedNotificationIsReadAndATamperedOneRefused` | the signed webhook is read as one event naming the provider (its transaction and product keeping their invariants); the tampered one and the unsigned one (no headers) are `InvalidNotificationException`s naming the provider |
| `testAPlatformOrderKeepsItsInvariants` | `fetchOrder()`: the provider's name, a reference, a total in an ISO currency, not negative |
| `testTheCatalogueIsPagedAndItsProductsKeepTheirInvariants` | `fetchProducts(limit 10)` gives products naming the provider with a reference and a title, and a `next` that is `null` or not empty, matching `hasMore()`; `fetchProduct()` finds the product named; neither supported: neither is `fetchInventory()` |

Each test of an operation the provider does not support asserts only that it is not supported.
Connected accounts and subscriptions are covered by the "supported or not" and error tests only:
their answers are checked by the provider's own tests (Stripe's `Tests/StripeConnectTest.php`).

What a failure looks like - a provider that catches the HTTP client's
`HttpExceptionInterface` only, so that a transport failure escapes:

```
1) Acme\Pay\Tests\AcmePayContractTest::testWhatItSupportsFailsAsAProviderErrorWhenTheProviderDoes
the provider's failures are ProviderExceptions
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
@@ @@
-Array &0 []
+Array &0 [
+    0 => 'Omnitrade\Request\Purchase on unreachable: Symfony\Component\HttpClient\Exception\TransportException ("Could not resolve host") instead of a ProviderException',
+    1 => 'Omnitrade\Request\FetchTransaction on unreachable: Symfony\Component\HttpClient\Exception\TransportException ("Could not resolve host") instead of a ProviderException',
+]
```

(run on 2026-10-25 on the provider of [Writing a provider](providers.md#a-complete-provider), its
`catch` narrowed on purpose.)

## Running the family's tests

- In the workspace (`~/Sites/omnitrade`: the core and the six providers as path repositories, one
  `phpunit.xml.dist` with a suite per package): `vendor/bin/phpunit`, 145 tests on 2026-10-25.
- In the [Docker harness](harness.md): `docker compose run --rm omnitrade test`, every package's
  tests in one run, the providers from GitHub or from the checkouts beside the core.
- In one provider's repository, after `composer install`: `vendor/bin/phpunit`.

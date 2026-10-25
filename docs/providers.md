---
title: Writing a provider
order: 80
---

## Writing a provider

A provider is a package of its own (`omnitrade/<service>`, namespace `Omnitrade\<Service>\`) that
requires `omniscient/omnitrade` and `symfony/http-client`. It holds:

- a factory extending `Omnitrade\GatewayFactory`, taking an optional `HttpClientInterface` in its
  constructor and filling the `Config` in `populateConfig()`: `omnitrade.factory_name` (the name
  `factory:` names in the configuration), `omnitrade.factory_title`, `omnitrade.required_options`,
  the defaults of its other options, `omnitrade.api` (its API client, a closure of the `Config`)
  and one `omnitrade.action.<name>` per thing it does;
- its actions (`Omnitrade\Action\ActionInterface`, `ApiAwareInterface` for those that talk to the
  service): each one supports one request and answers it with the family's models.

What it does not do, it has no action for: `supports()` then says no and the gateway throws
`RequestNotSupportedException`. An action that depends on an option (Amazon's reads, which need
credentials) is left out by its closure returning `null`.

### Errors: the family's rule

| What happens | What the provider throws |
|---|---|
| an option missing or empty | `InvalidConfigException` (the factory does it from `omnitrade.required_options`) |
| a request it has no action for | `RequestNotSupportedException`, before any HTTP request |
| the service answers HTTP 4xx or 5xx (401, 403, 404, 429, 500, 503...) | `ProviderException`, with the service's own error code in `providerCode` when it gives one |
| the service cannot be reached (DNS, connection, timeout) | `ProviderException`, the HTTP client's exception as `previous` |
| the service answers what cannot be read (an HTML page in place of JSON) | `ProviderException` |
| a webhook whose signature does not hold, or that is not signed | `InvalidNotificationException` (a `ProviderException`) |
| `fetchProduct()` of a product the service does not have | `null`, not an exception |

Never a `TypeError`, a `JsonException`, an HTTP client's exception (catch
`Symfony\Contracts\HttpClient\Exception\ExceptionInterface`, which a transport failure also
implements, not only `HttpExceptionInterface`) nor the exception of a library underneath
(omnipay's `Omnipay\Common\Http\Exception`). The family has no exception per status: a 401 is
a `ProviderException` like a 500, its message and `providerCode` saying which.

Rate limits: a provider may retry a 429 or a 5xx a few times (Shopify: 3 attempts), honouring
the answer's `Retry-After` when there is one, and throws a `ProviderException` once it gives up.
The wait is an option (Shopify's `retry_delay`) so that a test does not serve it.

Pagination: `fetchProducts()` answers a `ProductPage` whose `next` is the cursor of the next page,
`null` on the last one, never an empty string.

Money is in minor units (`Money::of(1250, 'EUR')` is 12,50 EUR): a service speaking decimals is
read with `Money::fromDecimal()` and written with `Money::decimal()`.

### Conformance suite

`Omnitrade\Testing\GatewayContractTests` is the family's contract as PHPUnit tests, offline. Every
provider's `Tests/<Service>ContractTest.php` uses it:

```php
use Omnitrade\Testing\GatewayContractTests;

final class PayPalContractTest extends TestCase
{
    use GatewayContractTests;

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new PayPalGatewayFactory($http);
    }

    protected function options(): array
    {
        return ['client_id' => 'cid', 'secret' => 'sec', 'webhook_id' => 'WH-1'];
    }

    protected function recordedAnswers(): array
    {
        return [
            'POST /v1/oauth2/token' => ['access_token' => 'A21AA', 'expires_in' => 32400],
            'GET /v2/checkout/orders/5O190127TN364715T' => [...],          // a JSON body, HTTP 200
            'POST /v2/checkout/orders' => fn (string $method, string $url, array $options) => new MockResponse(...),
        ];
    }

    protected function signedNotification(): ?array
    {
        return [$body, ['PAYPAL-TRANSMISSION-SIG' => ...]];
    }
}
```

The provider gives its factory (built on the `HttpClientInterface` the suite hands it: a
`MockHttpClient`), fake options turning everything on (a webhook secret, optional credentials),
and the answers its service would give, by `"METHOD /path"`. A request with no recorded answer is
answered HTTP 404, and the failure names it. Optional hooks: `transactionReference()`,
`refundReference()` (a captured payment, where an authorization alone has nothing to refund),
`productReference()`, `catalogueQuery()`, `tamperedNotification()` (when tampering is not a change
of the body: PayPal verifies signatures on its side), `payment()`, `requiredOptions()`.

What it checks:

- the factory's name is the built gateway's; a `Registry` builds it from `{factory, options}`;
- each required option missing or empty is an `InvalidConfigException` (no required option: it
  builds with none);
- every request `supports()` denies throws `RequestNotSupportedException`, before any HTTP request;
- every request it grants, when the service answers HTTP 401, 403, 404, 429, 500, 503 (an HTML
  page), HTTP 200 with an HTML page, or cannot be reached, answers or throws a `ProviderException`
  naming the provider - nothing else; `fetchProduct()` on a 404 answers `null` when it answers;
- on the recorded answers, for each operation supported: `purchase()`/`authorize()` give a
  transaction of the payment's amount in minor units (PENDING, AUTHORIZED or PAID; a redirect
  to an http(s) page), `fetch()` a transaction, `capture()` one no longer authorized, `void()` a
  CANCELLED one, `refund()` of 5,00 EUR a refund of 500 EUR cents with the provider's id,
  `paymentMethods()` described methods (a kind among the constants, ISO currencies),
  `fetchOrder()` an order with its total, `fetchProducts()` a page of products with a valid cursor,
  `fetchProduct()` the product; every model carries the gateway's name as its provider and a
  non-empty reference;
- `notify()`: the signed webhook is read as one event; the tampered one and the unsigned one are
  `InvalidNotificationException`s.

Connected accounts and subscriptions (Stripe) are covered by the "supported or not" and error
checks only; their answers are tested by the provider's own tests.

Every hook, and what each of the twelve tests checks: [Testing](testing.md#the-conformance-suite).

Run it with the provider's other tests: in the workspace (`~/Sites/omnitrade`, path repositories
and one `phpunit.xml.dist`), or through the Docker harness (`docker compose run --rm omnitrade test`).

### A complete provider

A payment service of one's own - here "Acme Pay", a hosted page at `api.acme-pay.example` with a
bearer key - in one file for the reading (a package would put each class in its own file under
`Omnitrade\AcmePay\`, or the application's namespace for a provider of its own):

```php
<?php

namespace Acme\Pay;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Config;
use Omnitrade\Exception\ProviderException;
use Omnitrade\GatewayFactory;
use Omnitrade\Model\Money;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\Request\FetchTransaction;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A provider of one's own: a hosted payment page at api.acme-pay.example, bearer key. */
final class AcmePayGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnitrade.factory_name' => 'acme_pay',
            'omnitrade.factory_title' => 'Acme Pay',
            'omnitrade.required_options' => ['api_key'],
            'sandbox' => false,
            'omnitrade.api' => fn (Config $c) => new Api($this->http ?? HttpClient::create(), (string) $c['api_key'], (bool) $c['sandbox']),
            'omnitrade.action.purchase' => new PurchaseAction(),
            'omnitrade.action.fetch' => new FetchAction(),
        ]);
    }
}

final class Api
{
    public function __construct(private readonly HttpClientInterface $http, private readonly string $key, private readonly bool $sandbox)
    {
    }

    /** @return array<string, mixed> */
    public function call(string $method, string $path, ?array $json = null): array
    {
        try {
            $response = $this->http->request($method, ($this->sandbox ? 'https://sandbox.acme-pay.example' : 'https://api.acme-pay.example').$path, ['auth_bearer' => $this->key, 'json' => $json, 'timeout' => 15]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface $e) {   // a transport failure too, not only an HTTP status
            throw new ProviderException('acme_pay', 'Acme Pay request failed: '.$e->getMessage(), null, $e);
        }
        if (!\is_array($data)) {
            throw new ProviderException('acme_pay', \sprintf('Acme Pay answered HTTP %d with a body that is not JSON.', $status));
        }
        if ($status >= 400) {
            throw new ProviderException('acme_pay', (string) ($data['message'] ?? 'HTTP '.$status), isset($data['error']) ? (string) $data['error'] : null);
        }

        return $data;
    }

    /** @param array<string, mixed> $payment */
    public static function transaction(array $payment): Transaction
    {
        $status = match ($payment['state'] ?? null) {
            'succeeded' => Status::PAID,
            'failed' => Status::REFUSED,
            default => Status::PENDING,
        };

        return new Transaction('acme_pay', (string) $payment['id'], $status, Money::of((int) $payment['amount'], (string) $payment['currency']),
            redirectUrl: Status::PENDING === $status ? ($payment['checkout_url'] ?? null) : null, raw: $payment);
    }
}

final class PurchaseAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Purchase;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Purchase);
        $payment = $request->payment;
        $answer = $this->api->call('POST', '/v1/payments', ['amount' => $payment->amount->amount, 'currency' => $payment->amount->currency, 'reference' => $payment->reference, 'return_url' => $payment->returnUrl]);
        $request->setResult(Api::transaction($answer));
    }
}

final class FetchAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchTransaction;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchTransaction);
        $request->setResult(Api::transaction($this->api->call('GET', '/v1/payments/'.rawurlencode($request->reference))));
    }
}
```

Its conformance test, on two recorded answers - the payment created, then read once paid:

```php
<?php

namespace Acme\Pay\Tests;

use Acme\Pay\AcmePayGatewayFactory;
use Omnitrade\GatewayFactoryInterface;
use Omnitrade\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;


final class AcmePayContractTest extends TestCase
{
    use GatewayContractTests;

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new AcmePayGatewayFactory($http);
    }

    protected function options(): array
    {
        return ['api_key' => 'test_key', 'sandbox' => true];
    }

    protected function transactionReference(): string
    {
        return 'pay_1';
    }

    protected function recordedAnswers(): array
    {
        $payment = ['id' => 'pay_1', 'amount' => 1250, 'currency' => 'EUR'];

        return [
            'POST /v1/payments' => $payment + ['state' => 'pending', 'checkout_url' => 'https://sandbox.acme-pay.example/pay/pay_1'],
            'GET /v1/payments/pay_1' => $payment + ['state' => 'succeeded'],
        ];
    }
}
```

```
$ vendor/bin/phpunit --testdox tests/AcmePayContractTest.php
Acme Pay Contract (Acme\Pay\Tests\AcmePayContract)
 ✔ The factory names the gateway it builds
 ✔ A missing required option is a configuration error
 ✔ What it does not support is refused as not supported
 ✔ What it supports fails as a provider error when the provider does
 ✔ A purchase is a transaction in minor units
 ✔ A fetched transaction keeps its invariants
 ✔ Capture and void answer transactions
 ✔ A refund is recorded in minor units
 ✔ Payment methods are described
 ✔ A signed notification is read and a tampered one refused
 ✔ A platform order keeps its invariants
 ✔ The catalogue is paged and its products keep their invariants

OK (12 tests, 67 assertions)
```

(run on 2026-10-25 in the harness image, PHP 8.4, PHPUnit 11.5.) The tests of what it does not
do (capture, refunds, webhooks, the catalogue) only check that `supports()` says no. Narrowing the
`catch` to `HttpExceptionInterface` makes the fourth test fail on the unreachable service: the
failure is shown in [Testing](testing.md#what-each-test-checks).

What the example follows:

- the factory's name is snake_case (`acme_pay`) and every model carries it as `provider`; the
  exceptions carry it too;
- the API client is built by a closure of the `Config`, so building the gateway calls nothing;
- every failure of the service goes through one method (`Api::call()`) that turns it into a
  `ProviderException`: `Symfony\Contracts\HttpClient\Exception\ExceptionInterface` (a transport
  failure included), a body that is not JSON, an HTTP status of 400 or more;
- money stays in minor units; a decimal service is read with `Money::fromDecimal()` and written
  with `Money::decimal()`;
- what the service answered is kept in `raw`.

### The package

A provider package (`omnitrade/<service>` on GitHub as `glitchr-studio/omnitrade-<service>`, or any
name for a provider of one's own) requires `omniscient/omnitrade` and `symfony/http-client`, and
`phpunit/phpunit` in `require-dev` for the suite:

```json
{
    "name": "omnitrade/acme-pay",
    "type": "library",
    "require": {
        "php": ">=8.2",
        "omniscient/omnitrade": "^1.0@dev",
        "symfony/http-client": "^6.4|^7.0|^8.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {"Omnitrade\\AcmePay\\": ""},
        "exclude-from-classmap": ["/Tests/"]
    },
    "autoload-dev": {
        "psr-4": {"Omnitrade\\AcmePay\\Tests\\": "Tests/"}
    }
}
```

The Symfony bundle registers the six providers it knows by class; another factory joins the
registry once it is a service of the application (autoconfigured: tagged
`omnitrade.gateway_factory`). Its README says what the service is, its credentials, its options
and, as every provider of the family does, its Responsibility: an unofficial client, the
service's terms named with a link.


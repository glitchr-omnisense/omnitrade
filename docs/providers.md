# Writing a provider

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

## Errors: the family's rule

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

## Conformance suite

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

Run it with the provider's other tests: in the workspace (`~/Sites/omnitrade`, path repositories
and one `phpunit.xml.dist`), or through the Docker harness (`docker compose run --rm omnitrade test`).

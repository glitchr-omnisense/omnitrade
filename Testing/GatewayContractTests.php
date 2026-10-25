<?php

namespace Omnitrade\Testing;

use Omnitrade\Exception\InvalidConfigException;
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Exception\RequestNotSupportedException;
use Omnitrade\GatewayFactory;
use Omnitrade\GatewayFactoryInterface;
use Omnitrade\GatewayInterface;
use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Model\Product;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\Registry;
use Omnitrade\Request;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What every Omnitrade provider does, as PHPUnit tests, offline: a provider's
 * test case uses this trait, gives its factory (built on the HTTP client the
 * suite hands it) and fake options, and the provider's recorded answers for
 * the operations it supports.
 *
 *   final class PayPalContractTest extends TestCase
 *   {
 *       use GatewayContractTests;
 *
 *       protected function factory(HttpClientInterface $http): GatewayFactoryInterface { return new PayPalGatewayFactory($http); }
 *       protected function options(): array { return ['client_id' => 'cid', 'secret' => 'sec', 'webhook_id' => 'WH-1']; }
 *       protected function recordedAnswers(): array { return ['POST /v1/oauth2/token' => ['access_token' => 'A21AA'], ...]; }
 *   }
 *
 * It checks:
 * - the factory's name is the built gateway's, and a Registry builds it from {factory, options};
 * - each required option missing is an InvalidConfigException (none required: it builds with none);
 * - what supports() denies is refused with RequestNotSupportedException, before any HTTP request;
 * - what supports() grants answers, or fails as a ProviderException - never a TypeError, a
 *   JsonException nor an HTTP client's exception - when the provider answers HTTP 401, 403, 404,
 *   429, 500, 503, a body that is not what it should be, or cannot be reached (the family's rule,
 *   docs/providers.md); fetchProduct() of what the provider does not have (HTTP 404) is null;
 * - on the recorded answers, for each supported operation: transactions, refunds, payment methods,
 *   platform orders and catalogue pages keep their invariants (the gateway's name as provider,
 *   non-empty references, money in minor units in an ISO currency, a void CANCELLED, a refund of
 *   5,00 EUR recorded as 500);
 * - notify(): a signed webhook is read as one event, a tampered or unsigned one is an
 *   InvalidNotificationException.
 *
 * No test reaches the network: every request goes to the suite's MockHttpClient, and one with no
 * recorded answer is answered HTTP 404 and named in the failure.
 *
 * Requires phpunit/phpunit and symfony/http-client (the provider's, in require-dev).
 */
trait GatewayContractTests
{
    /** @var list<string> the requests made with no recorded answer, for the failure messages */
    private array $contractUnanswered = [];

    /** The provider's factory, talking through $http. */
    abstract protected function factory(HttpClientInterface $http): GatewayFactoryInterface;

    /**
     * Options that build the gateway with everything it offers turned on (a webhook secret,
     * credentials for an optional API...): fake values.
     *
     * @return array<string, mixed>
     */
    abstract protected function options(): array;

    /**
     * The provider's answers, by "METHOD /path" (the query left out): a JSON body as an array
     * (HTTP 200), a MockResponse, or a callable (string $method, string $url, array $options):
     * MockResponse, for an endpoint whose answer depends on the request (a GraphQL one).
     *
     * @return array<string, array<mixed>|MockResponse|callable>
     */
    protected function recordedAnswers(): array
    {
        return [];
    }

    /** The options that must be given; by default what a GatewayFactory's Config requires. */
    protected function requiredOptions(): array
    {
        $factory = $this->factory(new MockHttpClient());

        return $factory instanceof GatewayFactory ? (array) $factory->createConfig()->get('omnitrade.required_options', []) : [];
    }

    /** The payment purchase(), authorize() and subscribe() are asked with. */
    protected function payment(): Payment
    {
        return new Payment(
            amount: Money::of(1250, 'EUR'),
            reference: 'ORDER-1042',
            description: 'Order 1042',
            customer: new Customer(email: 'camille@example.org', name: 'Camille Durand', country: 'FR'),
            lines: [new Line('Ten hours', Money::of(1250, 'EUR'))],
            returnUrl: 'https://shop.example/return',
            cancelUrl: 'https://shop.example/cart',
            idempotencyKey: 'attempt-1',
        );
    }

    /** The transaction (or platform order) fetch(), capture(), refund(), void() and fetchOrder() name. */
    protected function transactionReference(): string
    {
        return 'contract-transaction';
    }

    /** The payment refund() gives money back on; by default transactionReference(). */
    protected function refundReference(): string
    {
        return $this->transactionReference();
    }

    /** The product fetchProduct() and affiliateLink() name: an id there, or a page's address. */
    protected function productReference(): string
    {
        return 'contract-product';
    }

    /** The search fetchProducts() is asked with; null: the whole catalogue. */
    protected function catalogueQuery(): ?string
    {
        return null;
    }

    /**
     * A webhook as the provider signs it: [body, headers]. Needed when notify() is supported.
     *
     * @return array{0: string, 1: array<string, string|string[]>}|null
     */
    protected function signedNotification(): ?array
    {
        return null;
    }

    /**
     * The same webhook, tampered with; by default its body with one more character.
     *
     * @return array{0: string, 1: array<string, string|string[]>}
     */
    protected function tamperedNotification(): array
    {
        [$body, $headers] = $this->signedNotification() ?? ['{}', []];

        return [$body.' ', $headers];
    }

    public function testTheFactoryNamesTheGatewayItBuilds(): void
    {
        $factory = $this->factory($this->contractHttp());
        $gateway = $factory->create($this->options());

        self::assertNotSame('', $factory->getName());
        self::assertSame($factory->getName(), $gateway->getName(), 'the gateway carries its factory\'s name');
        self::assertNotSame('', $gateway->getTitle());

        $registry = new Registry([$factory], ['contract' => ['factory' => $factory->getName(), 'options' => $this->options()]]);
        self::assertSame([$factory->getName()], $registry->factories());
        self::assertSame($factory->getName(), $registry->get('contract')->getName(), 'a Registry builds it from {factory, options}');
        self::assertSame($registry->get('contract'), $registry->get('contract'), 'built once');
    }

    public function testAMissingRequiredOptionIsAConfigurationError(): void
    {
        $required = $this->requiredOptions();
        if (!$required) {
            $gateway = $this->factory($this->contractHttp())->create([]);
            self::assertInstanceOf(GatewayInterface::class, $gateway, 'no required option: it builds with none');

            return;
        }
        foreach ($required as $option) {
            $options = $this->options();
            self::assertArrayHasKey($option, $options, 'options() gives every required option');
            unset($options[$option]);
            try {
                $this->factory($this->contractHttp())->create($options);
                self::fail(\sprintf('built without "%s"', $option));
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString($option, $e->getMessage());
            }
            $options[$option] = '';
            try {
                $this->factory($this->contractHttp())->create($options);
                self::fail(\sprintf('built with an empty "%s"', $option));
            } catch (InvalidConfigException) {
            }
        }
    }

    public function testWhatItDoesNotSupportIsRefusedAsNotSupported(): void
    {
        $requests = [];
        $gateway = $this->factory(new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method.' '.$url;

            return new MockResponse('{}', ['http_code' => 500]);
        }))->create($this->options());

        foreach ($this->contractCalls() as $request => $call) {
            if ($gateway->supports($request)) {
                continue;
            }
            $requests = [];
            try {
                $call($gateway);
                self::fail(\sprintf('%s is not supported, yet answered', $request));
            } catch (RequestNotSupportedException $e) {
                self::assertStringContainsString($gateway->getName(), $e->getMessage());
            }
            self::assertSame([], $requests, \sprintf('%s refused before any HTTP request', $request));
        }
    }

    public function testWhatItSupportsFailsAsAProviderErrorWhenTheProviderDoes(): void
    {
        $failures = [
            'HTTP 401' => fn () => new MockResponse('{"error":"unauthorized","message":"Invalid credentials"}', ['http_code' => 401]),
            'HTTP 403' => fn () => new MockResponse('{"error":"forbidden","message":"Forbidden"}', ['http_code' => 403]),
            'HTTP 404' => fn () => new MockResponse('{"error":"not_found","message":"Not found"}', ['http_code' => 404]),
            'HTTP 429' => fn () => new MockResponse('{"error":"rate_limited","message":"Too many requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '0']]),
            'HTTP 500' => fn () => new MockResponse('{"error":"server_error","message":"Internal error"}', ['http_code' => 500]),
            'HTTP 503 (an HTML page)' => fn () => new MockResponse('<html><body>Service Unavailable</body></html>', ['http_code' => 503, 'response_headers' => ['content-type' => 'text/html']]),
            'HTTP 200, not the expected body' => fn () => new MockResponse('<html><body>Maintenance</body></html>', ['response_headers' => ['content-type' => 'text/html']]),
            'unreachable' => fn () => new MockResponse('', ['error' => 'Could not resolve host']),
        ];
        $supported = 0;
        $problems = [];
        foreach ($failures as $failure => $response) {
            $gateway = $this->factory(new MockHttpClient(static fn () => $response()))->create($this->options());
            foreach ($this->contractCalls() as $request => $call) {
                if (!$gateway->supports($request)) {
                    continue;
                }
                ++$supported;
                try {
                    $result = $call($gateway);
                } catch (ProviderException $e) {
                    if ($gateway->getName() !== $e->provider) {
                        $problems[] = \sprintf('%s on %s: the exception names "%s", not the provider', $request, $failure, $e->provider);
                    }
                    continue;
                } catch (\Throwable $e) {
                    $problems[] = \sprintf('%s on %s: %s ("%s") instead of a ProviderException', $request, $failure, $e::class, $e->getMessage());
                    continue;
                }
                if ('HTTP 404' === $failure && Request\FetchProduct::class === $request && null !== $result) {
                    $problems[] = 'fetchProduct() on HTTP 404: a product instead of null';
                }
            }
        }
        self::assertGreaterThan(0, $supported, 'the provider supports something');
        self::assertSame([], $problems, 'the provider\'s failures are ProviderExceptions');
    }

    public function testAPurchaseIsATransactionInMinorUnits(): void
    {
        $gateway = $this->contractGateway();
        foreach ([Request\Purchase::class => 'purchase', Request\Authorize::class => 'authorize'] as $request => $method) {
            if (!$gateway->supports($request)) {
                self::assertFalse($gateway->supports($request));
                continue;
            }
            $payment = $this->payment();
            $transaction = $this->answered(fn () => $gateway->{$method}($payment), $method);
            $this->assertTransaction($transaction, $gateway, $method);
            if (null !== $transaction->amount) {
                self::assertTrue($transaction->amount->equals($payment->amount), \sprintf('%s(): %s asked, %s recorded', $method, $payment->amount, $transaction->amount));
            }
            self::assertContains($transaction->status, [Status::PENDING, Status::AUTHORIZED, Status::PAID], \sprintf('%s(): a payment just asked for', $method));
            if (Status::PENDING === $transaction->status && null !== $transaction->redirectUrl) {
                self::assertTrue($transaction->isRedirect());
            }
        }
    }

    public function testAFetchedTransactionKeepsItsInvariants(): void
    {
        $gateway = $this->contractGateway();
        if (!$gateway->supports(Request\FetchTransaction::class)) {
            self::assertFalse($gateway->supports(Request\FetchTransaction::class));

            return;
        }
        $transaction = $this->answered(fn () => $gateway->fetch($this->transactionReference()), 'fetch');
        $this->assertTransaction($transaction, $gateway, 'fetch');
    }

    public function testCaptureAndVoidAnswerTransactions(): void
    {
        $gateway = $this->contractGateway();
        $supported = false;
        if ($gateway->supports(Request\Capture::class)) {
            $supported = true;
            $captured = $this->answered(fn () => $gateway->capture($this->transactionReference()), 'capture');
            $this->assertTransaction($captured, $gateway, 'capture');
            self::assertNotContains($captured->status, [Status::AUTHORIZED, Status::CANCELLED, Status::EXPIRED], 'capture(): no longer only authorized');
        }
        if ($gateway->supports(Request\VoidAuthorization::class)) {
            $supported = true;
            $voided = $this->answered(fn () => $gateway->void($this->transactionReference()), 'void');
            $this->assertTransaction($voided, $gateway, 'void');
            self::assertSame(Status::CANCELLED, $voided->status, 'void(): the authorization let go');
        }
        if (!$supported) {
            self::assertFalse($gateway->supports(Request\Authorize::class), 'authorizations come with capture() and void()');
        }
    }

    public function testARefundIsRecordedInMinorUnits(): void
    {
        $gateway = $this->contractGateway();
        if (!$gateway->supports(Request\Refund::class)) {
            self::assertFalse($gateway->supports(Request\Refund::class));

            return;
        }
        $refund = $this->answered(fn () => $gateway->refund($this->refundReference(), Money::of(500, 'EUR'), 'refund-1', 'Overcharged'), 'refund');
        self::assertSame($gateway->getName(), $refund->provider);
        self::assertNotSame('', $refund->reference, 'refund(): the provider\'s refund id');
        self::assertTrue($refund->amount->equals(Money::of(500, 'EUR')), \sprintf('refund(): 5.00 EUR asked, %s recorded', $refund->amount));
        self::assertContains($refund->status, [Status::PENDING, Status::REFUNDED, Status::PARTIALLY_REFUNDED, Status::REFUSED, Status::CANCELLED], 'refund(): a refund\'s status');
    }

    public function testPaymentMethodsAreDescribed(): void
    {
        $gateway = $this->contractGateway();
        if (!$gateway->supports(Request\GetPaymentMethods::class)) {
            self::assertFalse($gateway->supports(Request\GetPaymentMethods::class));

            return;
        }
        $methods = $this->answered(fn () => $gateway->paymentMethods(Money::of(1250, 'EUR'), 'FR'), 'paymentMethods');
        self::assertIsList($methods);
        foreach ($methods as $method) {
            self::assertInstanceOf(PaymentMethod::class, $method);
            self::assertNotSame('', $method->code);
            self::assertNotSame('', $method->label);
            self::assertContains($method->kind, [PaymentMethod::CARD, PaymentMethod::WALLET, PaymentMethod::BANK, PaymentMethod::BUY_NOW_PAY_LATER, PaymentMethod::OTHER]);
            foreach ($method->currencies as $currency) {
                self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $currency);
            }
        }
    }

    public function testASignedNotificationIsReadAndATamperedOneRefused(): void
    {
        $gateway = $this->contractGateway();
        if (!$gateway->supports(Request\Notify::class)) {
            self::assertFalse($gateway->supports(Request\Notify::class));

            return;
        }
        $signed = $this->signedNotification();
        self::assertNotNull($signed, 'notify() is supported: signedNotification() gives a webhook to read');
        [$body, $headers] = $signed;

        $notification = $this->answered(fn () => $gateway->notify($body, $headers), 'notify');
        self::assertSame($gateway->getName(), $notification->provider);
        self::assertNotSame('', $notification->event, 'notify(): the provider\'s event type');
        if (null !== $notification->transaction) {
            $this->assertTransaction($notification->transaction, $gateway, 'notify');
        }
        if (null !== $notification->product) {
            $this->assertProduct($notification->product, $gateway, 'notify');
        }

        foreach (['tampered' => $this->tamperedNotification(), 'unsigned' => [$body, []]] as $case => [$tamperedBody, $tamperedHeaders]) {
            try {
                $gateway->notify($tamperedBody, $tamperedHeaders);
                self::fail(\sprintf('notify() read a %s webhook', $case));
            } catch (InvalidNotificationException $e) {
                self::assertSame($gateway->getName(), $e->provider);
            }
        }
    }

    public function testAPlatformOrderKeepsItsInvariants(): void
    {
        $gateway = $this->contractGateway();
        if (!$gateway->supports(Request\FetchOrder::class)) {
            self::assertFalse($gateway->supports(Request\FetchOrder::class));

            return;
        }
        $order = $this->answered(fn () => $gateway->fetchOrder($this->transactionReference()), 'fetchOrder');
        self::assertSame($gateway->getName(), $order->provider);
        self::assertNotSame('', $order->reference);
        self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $order->total->currency);
        self::assertGreaterThanOrEqual(0, $order->total->amount);
    }

    public function testTheCatalogueIsPagedAndItsProductsKeepTheirInvariants(): void
    {
        $gateway = $this->contractGateway();
        $supported = false;
        if ($gateway->supports(Request\FetchProducts::class)) {
            $supported = true;
            $page = $this->answered(fn () => $gateway->fetchProducts(null, null, $this->catalogueQuery(), 10), 'fetchProducts');
            self::assertIsList($page->products);
            self::assertNotSame([], $page->products, 'fetchProducts(): the recorded answers give a page with products');
            foreach ($page->products as $product) {
                $this->assertProduct($product, $gateway, 'fetchProducts');
            }
            self::assertNotSame('', $page->next, 'fetchProducts(): the next cursor is null on the last page, never empty');
            self::assertSame(null !== $page->next, $page->hasMore());
        }
        if ($gateway->supports(Request\FetchProduct::class)) {
            $supported = true;
            $product = $this->answered(fn () => $gateway->fetchProduct($this->productReference()), 'fetchProduct');
            self::assertNotNull($product, 'fetchProduct(): the recorded answers give the product named by productReference()');
            $this->assertProduct($product, $gateway, 'fetchProduct');
        }
        if (!$supported) {
            self::assertFalse($gateway->supports(Request\FetchInventory::class), 'no stock without a catalogue');
        }
    }

    /** @return array<class-string<Request\Request>, \Closure(GatewayInterface): mixed> every typed method, asked with plausible arguments */
    private function contractCalls(): array
    {
        $payment = $this->payment();
        $reference = $this->transactionReference();
        $product = $this->productReference();
        $query = $this->catalogueQuery();

        return [
            Request\Purchase::class => static fn (GatewayInterface $g) => $g->purchase($payment),
            Request\Authorize::class => static fn (GatewayInterface $g) => $g->authorize($payment),
            Request\Capture::class => static fn (GatewayInterface $g) => $g->capture($reference),
            Request\Refund::class => static fn (GatewayInterface $g) => $g->refund($reference, Money::of(500, 'EUR')),
            Request\VoidAuthorization::class => static fn (GatewayInterface $g) => $g->void($reference),
            Request\FetchTransaction::class => static fn (GatewayInterface $g) => $g->fetch($reference),
            Request\GetPaymentMethods::class => static fn (GatewayInterface $g) => $g->paymentMethods(Money::of(1250, 'EUR'), 'FR'),
            Request\Notify::class => static fn (GatewayInterface $g) => $g->notify('{"id":"evt_contract","type":"contract"}', []),
            Request\FetchOrder::class => static fn (GatewayInterface $g) => $g->fetchOrder($reference),
            Request\FetchProduct::class => static fn (GatewayInterface $g) => $g->fetchProduct($product),
            Request\FetchProducts::class => static fn (GatewayInterface $g) => $g->fetchProducts(null, null, $query, 10),
            Request\FetchInventory::class => static fn (GatewayInterface $g) => $g->fetchInventory([]),
            Request\AffiliateLink::class => static fn (GatewayInterface $g) => $g->affiliateLink($product),
            Request\CreateAccount::class => static fn (GatewayInterface $g) => $g->createAccount('FR', 'camille@example.org'),
            Request\AccountLink::class => static fn (GatewayInterface $g) => $g->accountLink('acct_contract', 'https://shop.example/return', 'https://shop.example/again'),
            Request\FetchAccount::class => static fn (GatewayInterface $g) => $g->fetchAccount('acct_contract'),
            Request\Subscribe::class => static fn (GatewayInterface $g) => $g->subscribe($payment),
            Request\FetchSubscription::class => static fn (GatewayInterface $g) => $g->fetchSubscription('sub_contract'),
            Request\CancelSubscription::class => static fn (GatewayInterface $g) => $g->cancelSubscription('sub_contract'),
            Request\SubscriptionPortal::class => static fn (GatewayInterface $g) => $g->subscriptionPortal('cus_contract', 'https://shop.example/account'),
        ];
    }

    private function contractGateway(): GatewayInterface
    {
        return $this->factory($this->contractHttp())->create($this->options());
    }

    /** The suite's HTTP client: the recorded answers, HTTP 404 for anything else. */
    private function contractHttp(): MockHttpClient
    {
        $answers = $this->recordedAnswers();
        $this->contractUnanswered = [];

        return new MockHttpClient(function (string $method, string $url, array $options) use ($answers): MockResponse {
            $answer = $answers[$method.' '.parse_url($url, \PHP_URL_PATH)] ?? null;
            if (\is_callable($answer)) {
                $answer = $answer($method, $url, $options);
            }
            if (\is_array($answer)) {
                $answer = new MockResponse(json_encode($answer, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (!$answer instanceof MockResponse) {
                $this->contractUnanswered[] = $method.' '.$url;

                return new MockResponse('{"error":"not_found","message":"No recorded answer"}', ['http_code' => 404]);
            }

            return $answer;
        });
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function answered(\Closure $call, string $operation): mixed
    {
        try {
            return $call();
        } catch (ProviderException $e) {
            self::fail(\sprintf('%s() on the recorded answers: %s%s', $operation, $e->getMessage(), $this->contractUnanswered ? ' (no recorded answer for: '.implode(', ', $this->contractUnanswered).')' : ''));
        }
    }

    private function assertTransaction(Transaction $transaction, GatewayInterface $gateway, string $operation): void
    {
        self::assertSame($gateway->getName(), $transaction->provider, $operation.'(): the transaction names its provider');
        self::assertNotSame('', $transaction->reference, $operation.'(): the provider\'s reference');
        foreach (['amount' => $transaction->amount, 'refunded' => $transaction->refunded] as $what => $money) {
            if (null !== $money) {
                self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $money->currency, \sprintf('%s(): the %s\'s currency', $operation, $what));
                self::assertGreaterThanOrEqual(0, $money->amount, \sprintf('%s(): the %s', $operation, $what));
            }
        }
        if (null !== $transaction->amount && null !== $transaction->refunded) {
            self::assertSame($transaction->amount->currency, $transaction->refunded->currency);
            self::assertLessThanOrEqual($transaction->amount->amount, $transaction->refunded->amount, $operation.'(): no more refunded than paid');
        }
        if (null !== $transaction->redirectUrl) {
            self::assertMatchesRegularExpression('#^https?://#', $transaction->redirectUrl, $operation.'(): the page the buyer is sent to');
        }
    }

    private function assertProduct(Product $product, GatewayInterface $gateway, string $operation): void
    {
        self::assertSame($gateway->getName(), $product->provider, $operation.'(): the product names its provider');
        self::assertNotSame('', $product->reference, $operation.'(): the product\'s id there');
        self::assertNotSame('', $product->title, $operation.'(): the product\'s title');
    }
}

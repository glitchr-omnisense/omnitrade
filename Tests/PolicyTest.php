<?php

namespace Omnitrade\Tests;

use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Config;
use Omnitrade\Exception\OmnitradeException;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Exception\RateLimitedException;
use Omnitrade\Exception\UsageLimitReachedException;
use Omnitrade\GatewayFactory;
use Omnitrade\Http\Throttle;
use Omnitrade\Model\Money;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\Registry;
use Omnitrade\Request\Notify;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * omnitrade on omnisense/contracts' usage policies: a provider's declared
 * policy, a site's tightening, the guard Registry::guarded() puts in front of
 * a gateway, and what a 429 does to a create - surfaced, never retried, the
 * next calls held without being sent.
 */
final class PolicyTest extends TestCase
{
    /** @var list<array{string, array<string, string>}> url and headers of each request sent */
    private array $sent = [];

    /** @var list<MockResponse> the answers, in order */
    private array $answers = [];

    public function testAGatewayDeclaresItsPolicyAndItsOperations(): void
    {
        $registry = $this->registry();
        $gateway = $registry->get('pay');

        self::assertSame('policied', $gateway->capabilities()->policy()->provider);
        self::assertSame(['purchase', 'notify'], $gateway->capabilities()->contracts());
        self::assertSame(['pay'], array_keys($registry->policies()));
        self::assertSame(3, $registry->policy('pay')->limitsFor('purchase')[0]->max);
    }

    public function testAGuardedPurchaseOverTheLimitIsNeverSent(): void
    {
        $registry = $this->registry();
        $this->answers = array_fill(0, 3, self::paid());
        $guarded = $registry->guarded('pay');
        for ($i = 1; $i <= 3; ++$i) {
            $guarded->purchase(Fixtures::payment());
        }

        try {
            $guarded->purchase(Fixtures::payment());
            self::fail('a fourth purchase within the minute');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnitradeException::class, $e, 'one catch holds the family\'s exceptions');
            self::assertGreaterThan(0, $e->retryAfter);
        }
        self::assertCount(3, $this->sent, 'the fourth was not sent');
        self::assertSame($guarded, $registry->guarded('pay'), 'built once');
        self::assertNotSame($guarded, $registry->get('pay'), 'get() gives the gateway unguarded');
    }

    public function testASiteTightensThePolicyButNeverLoosensIt(): void
    {
        $tight = $this->registry(['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['purchase']]]]);
        $this->answers = [self::paid()];
        $tight->guarded('pay')->purchase(Fixtures::payment());
        try {
            $tight->guarded('pay')->purchase(Fixtures::payment());
            self::fail('the site allows one a minute');
        } catch (UsageLimitReachedException) {
        }
        self::assertCount(1, $this->sent);

        $this->expectException(InvalidPolicyException::class);
        $this->registry(['rateLimits' => [['max' => 10, 'window' => 'minute', 'operations' => ['purchase']]]])->policy('pay');
    }

    /**
     * A 429 to a create: a ProviderException (the family's rule) with the wait asked, the request
     * sent once and not again, the next call held without being sent, then allowed once the wait
     * is over - with the same idempotency key, so that the provider makes the payment once.
     */
    public function testA429ToACreateIsSurfacedNeverRetriedAndHoldsTheNextCalls(): void
    {
        $limiter = new InMemoryRateLimiter();
        $registry = $this->registry([], $limiter);
        $this->answers = [new MockResponse('{"error":"rate_limited"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30']])];
        $gateway = $registry->get('pay');

        try {
            $gateway->purchase(Fixtures::payment());
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertInstanceOf(ProviderException::class, $e);
            self::assertSame(30, $e->retryAfter);
            self::assertSame('rate_limited', $e->providerCode);
        }
        self::assertCount(1, $this->sent, 'sent once, not retried');

        try {
            $gateway->purchase(Fixtures::payment());
            self::fail('held while the wait lasts');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThan(0, $e->retryAfter);
        }
        self::assertCount(1, $this->sent, 'the held call was not sent');

        $this->answers = [self::paid()];
        $before = \count($this->sent);
        $other = (new PoliciedFactory($this->http()))->create(['omnitrade.limiter' => new InMemoryRateLimiter()]);
        self::assertTrue($other->purchase(Fixtures::payment())->isPaid());
        $again = $this->sent[$before] ?? null;
        self::assertIsArray($again);
        self::assertSame('attempt-1', $again[1]['Idempotency-Key'] ?? null, 'the same key, so the provider makes it once');
    }

    /**
     * The application's http_client with retry_failed on: Symfony's RetryableHttpClient would send
     * the payment again on a 503. The factory keeps its retries off: sent once, the 503 surfaced.
     */
    public function testAPaymentAnswered503IsSentOnceThroughARetryingClient(): void
    {
        $this->answers = [new MockResponse('{"error":"unavailable"}', ['http_code' => 503]), self::paid()];
        $retrying = new RetryableHttpClient($this->http(), new GenericRetryStrategy(GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES, 0), 3);
        $gateway = (new PoliciedFactory($retrying))->create(['token' => 't']);

        self::assertFalse($gateway->purchase(Fixtures::payment())->isPaid());
        self::assertCount(1, $this->sent, 'the 503 was not retried by the client');
        self::assertCount(1, $this->answers, 'the second answer was never asked for');
    }

    public function testAMockHttpClientIsKeptAsGiven(): void
    {
        $mock = new MockHttpClient([self::paid()]);
        (new PoliciedFactory($mock))->create(['token' => 't'])->purchase(Fixtures::payment());

        self::assertSame(1, $mock->getRequestsCount());
    }

    public function testAWebhookIsNeverHeldByTheGuard(): void
    {
        $registry = $this->registry(['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['notify']]]]);

        $registry->guarded('pay')->notify('{}');
        $registry->guarded('pay')->notify('{}');
        self::assertCount(0, $this->sent);
    }

    public function testThrottleWithoutABackoffStillRefusesA429(): void
    {
        Throttle::before(null);
        Throttle::after(null, 'policied', 200, []);
        $this->expectException(RateLimitedException::class);
        Throttle::after(null, 'policied', 429, []);
    }

    /** @param array<string, mixed> $policy */
    private function registry(array $policy = [], ?InMemoryRateLimiter $limiter = null): Registry
    {
        return new Registry([new PoliciedFactory($this->http())], ['pay' => ['factory' => 'policied', 'options' => ['token' => 't'], 'policy' => $policy]], $limiter);
    }

    private function http(): HttpClientInterface
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $line) {
                if (\is_string($line) && str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[$key] = trim($value);
                }
            }
            $this->sent[] = [$url, $headers];

            return array_shift($this->answers) ?? new MockResponse('{}', ['http_code' => 500]);
        });
    }

    private static function paid(): MockResponse
    {
        return new MockResponse('{"id":"tx_1","status":"paid"}');
    }
}

/** A provider over HTTP whose policy allows 3 purchases a minute, its client honouring the provider's waits. */
final class PoliciedFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnitrade.factory_name' => 'policied',
            'omnitrade.factory_title' => 'Policied',
            'omnitrade.policy' => UsagePolicy::fromArray(['provider' => 'policied', 'rateLimits' => [['max' => 3, 'window' => 'minute', 'operations' => ['purchase']]]]),
            'omnitrade.api' => fn (Config $c) => new PoliciedApi($this->http ?? new MockHttpClient(), $c->backoff()),
            'omnitrade.action.purchase' => new PoliciedPurchaseAction(),
            'omnitrade.action.notify' => new PoliciedNotifyAction(),
        ]);
    }
}

final class PoliciedApi
{
    public function __construct(private readonly HttpClientInterface $http, private readonly ?Backoff $backoff)
    {
    }

    /** @return array<mixed> */
    public function create(string $idempotencyKey): array
    {
        Throttle::before($this->backoff);
        $response = $this->http->request('POST', 'https://pay.example/payments', ['headers' => ['Idempotency-Key' => $idempotencyKey]]);
        Throttle::after($this->backoff, 'policied', $response->getStatusCode(), $response->getHeaders(false));

        return $response->toArray(false);
    }
}

final class PoliciedPurchaseAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<PoliciedApi> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = PoliciedApi::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Purchase;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Purchase);
        $data = $this->api->create((string) $request->payment->idempotencyKey);
        $id = $data['id'] ?? null;
        $request->setResult(new Transaction('policied', \is_string($id) ? $id : '', 'paid' === ($data['status'] ?? null) ? Status::PAID : Status::PENDING, Money::of(1250, 'EUR')));
    }
}

final class PoliciedNotifyAction implements ActionInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $request->setResult(new \Omnitrade\Model\Notification('policied', 'ping'));
    }
}

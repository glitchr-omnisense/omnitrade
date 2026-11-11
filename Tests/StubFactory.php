<?php

namespace Omnitrade\Tests;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Config;
use Omnitrade\GatewayFactory;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\Request\FetchTransaction;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;

/** A provider that takes everything at once, for the tests: its API is a counter. */
final class StubFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnitrade.factory_name' => 'stub',
            'omnitrade.factory_title' => 'Stub',
            'omnitrade.required_options' => ['token'],
            'omnitrade.api' => static fn (Config $c) => new StubApi((string) $c->string('token')),
            'omnitrade.action.purchase' => new StubPurchaseAction(),
            'omnitrade.action.fetch' => static fn (Config $c) => new StubFetchAction(),
        ]);
    }
}

final class StubApi
{
    public int $calls = 0;

    public function __construct(public readonly string $token)
    {
    }
}

final class StubPurchaseAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<StubApi> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = StubApi::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Purchase;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Purchase);
        ++$this->api->calls;
        $request->setResult(new Transaction('stub', 'tx_'.$this->api->token.'_'.$this->api->calls, Status::PAID, $request->payment->amount, raw: ['idempotency' => $request->payment->idempotencyKey]));
    }
}

final class StubFetchAction implements ActionInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof FetchTransaction;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchTransaction);
        $request->setResult(new Transaction('stub', $request->reference, Status::PAID));
    }
}

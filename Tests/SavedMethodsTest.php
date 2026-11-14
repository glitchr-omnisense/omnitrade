<?php

namespace Omnitrade\Tests;

use Omnisense\Contracts\Policy\UsagePolicy;
use Omnitrade\Action\ActionInterface;
use Omnitrade\Config;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Exception\UsageLimitReachedException;
use Omnitrade\Gateway;
use Omnitrade\GatewayFactory;
use Omnitrade\Model\Customer;
use Omnitrade\Model\MethodSetup;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Model\SavedMethod;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\Registry;
use Omnitrade\Request\CompleteMethodSetup;
use Omnitrade\Request\FetchSavedMethods;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\RemoveSavedMethod;
use Omnitrade\Request\Request;
use Omnitrade\Request\SetupMethod;
use PHPUnit\Framework\TestCase;

/** Saved payment methods (1.1, the setup completed 1.2): requests without a shortcut, answered by the action that supports them; Payment::$savedMethod. */
final class SavedMethodsTest extends TestCase
{
    public function testTheRequestsAreAnsweredAndListedAmongTheOperations(): void
    {
        $gateway = new Gateway('wallet', 'Wallet', [new WalletAction()]);

        $setup = $gateway->execute(new SetupMethod(new Customer('alice@example.org'), 'https://shop.example/saved', idempotencyKey: 'setup-1'))->getSetup();
        self::assertInstanceOf(MethodSetup::class, $setup);
        self::assertSame('cus_1', $setup->customer, 'a customer made for the occasion');
        self::assertSame('https://wallet.example/setup/set_1', $setup->redirectUrl);

        $methods = $gateway->execute(new FetchSavedMethods('cus_1'))->getMethods();
        self::assertCount(1, $methods);
        self::assertSame('Visa •••• 4242', $methods[0]->label);
        self::assertTrue($gateway->execute(new RemoveSavedMethod('cus_1', 'pm_1'))->isRemoved());

        $operations = $gateway->capabilities()->operations;
        foreach (['setupMethod', 'fetchSavedMethods', 'removeSavedMethod', 'purchase'] as $operation) {
            self::assertContains($operation, $operations);
        }
        self::assertSame('setupMethod', Gateway::operation(new SetupMethod(new Customer(), 'https://shop.example/')));
        self::assertFalse((new Gateway('none', 'None', []))->supports(SetupMethod::class));
    }

    public function testTheBuyerBackOnTheReturnUrlCompletesTheSetupIntoTheMethodItSaved(): void
    {
        $gateway = new Gateway('wallet', 'Wallet', [new WalletAction()]);
        $setup = $gateway->execute(new SetupMethod(new Customer('alice@example.org'), 'https://shop.example/saved'))->getSetup();
        self::assertNotNull($setup);

        $completed = $gateway->execute(new CompleteMethodSetup($setup->reference, $setup->customer));
        $method = $completed->getMethod();
        self::assertInstanceOf(SavedMethod::class, $method);
        self::assertSame('pm_1', $method->reference);
        self::assertSame('cus_1', $method->customer, 'the setup\'s customer\'s method');
        self::assertSame('pm_1', $gateway->execute(new CompleteMethodSetup($setup->reference, $setup->customer))->getMethod()?->reference, 'asked twice, the same method');

        self::assertSame('completeMethodSetup', Gateway::operation($completed));
        self::assertContains('completeMethodSetup', $gateway->capabilities()->operations);
        self::assertTrue($gateway->capabilities()->supports('completeMethodSetup'));
        self::assertFalse((new Gateway('none', 'None', []))->supports(CompleteMethodSetup::class));
        self::assertNotContains('completeMethodSetup', (new Gateway('setup-only', 'Setup only', [new SetupOnlyAction()]))->capabilities()->operations, 'a provider with nothing to complete does not list it');
    }

    public function testASetupOfAnotherCustomerOrNotFinishedSavesNothing(): void
    {
        $gateway = new Gateway('wallet', 'Wallet', [new WalletAction()]);
        foreach ([['set_1', 'cus_2', CompleteMethodSetup::NOT_THIS_CUSTOMER], ['set_open', 'cus_1', CompleteMethodSetup::NOT_COMPLETED]] as [$reference, $customer, $code]) {
            try {
                $gateway->execute(new CompleteMethodSetup($reference, $customer));
                self::fail(\sprintf('%s completed for %s', $reference, $customer));
            } catch (ProviderException $e) {
                self::assertSame($code, $e->providerCode);
                self::assertSame('wallet', $e->provider);
            }
        }
        self::assertSame('not_this_customer', CompleteMethodSetup::NOT_THIS_CUSTOMER, 'the code RemoveSavedMethod\'s providers give too');
    }

    public function testAGuardedGatewayCountsTheCompletionsAgainstThePolicy(): void
    {
        $registry = new Registry([new WalletFactory()], ['wallet' => ['factory' => 'wallet']]);
        $guarded = $registry->guarded('wallet');

        self::assertSame(1, $registry->policy('wallet')->limitsFor('completeMethodSetup')[0]->max, 'the policy names the operation');
        self::assertSame('pm_1', $guarded->execute(new CompleteMethodSetup('set_1', 'cus_1'))->getMethod()?->reference);
        try {
            $guarded->execute(new CompleteMethodSetup('set_1', 'cus_1'));
            self::fail('a second completion within the minute');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThan(0, $e->retryAfter);
        }
        self::assertSame(1, WalletAction::$completions, 'the second was never asked');
        self::assertSame('pm_1', $registry->get('wallet')->execute(new CompleteMethodSetup('set_1', 'cus_1'))->getMethod()?->reference, 'unguarded, as before');
    }

    public function testAPaymentNamesTheSavedMethodItCharges(): void
    {
        $gateway = new Gateway('wallet', 'Wallet', [new WalletAction()]);
        $payment = new Payment(Money::of(1990, 'EUR'), 'ORDER-7', customer: new Customer(reference: 'cus_1'), idempotencyKey: 'buy-7', savedMethod: 'pm_1');

        $paid = $gateway->purchase($payment);

        self::assertTrue($paid->isPaid(), 'charged at once, no page');
        self::assertNull($paid->redirectUrl);
        self::assertNull(Fixtures::payment()->savedMethod, 'a payment names none unless told: the provider\'s page, as before');
    }

    public function testASavedCardKnowsWhenItExpired(): void
    {
        $card = new SavedMethod('pm_1', 'cus_1', 'card', 'Visa •••• 4242', PaymentMethod::CARD, 'visa', '4242', 12, 2030);

        self::assertFalse($card->isExpired(new \DateTimeImmutable('2030-12-31')));
        self::assertTrue($card->isExpired(new \DateTimeImmutable('2031-01-01')));
        self::assertFalse((new SavedMethod('ba_1', null, 'paypal', 'PayPal'))->isExpired(), 'no expiry, never expired');
    }
}

final class WalletAction implements ActionInterface
{
    public static int $completions = 0;

    public function supports(Request $request): bool
    {
        return $request instanceof SetupMethod || $request instanceof CompleteMethodSetup || $request instanceof FetchSavedMethods || $request instanceof RemoveSavedMethod || $request instanceof Purchase;
    }

    public function execute(Request $request): void
    {
        if ($request instanceof CompleteMethodSetup) {
            ++self::$completions;
            if (null !== $request->customer && 'cus_1' !== $request->customer) {
                throw new ProviderException('wallet', 'Not this customer\'s setup.', CompleteMethodSetup::NOT_THIS_CUSTOMER);
            }
            if ('set_1' !== $request->reference) {
                throw new ProviderException('wallet', 'The buyer has not finished.', CompleteMethodSetup::NOT_COMPLETED);
            }
            $request->setResult(new SavedMethod('pm_1', 'cus_1', 'card', 'Visa •••• 4242', PaymentMethod::CARD, 'visa', '4242', 12, 2030));

            return;
        }
        match (true) {
            $request instanceof SetupMethod => $request->setResult(new MethodSetup('wallet', 'set_1', 'https://wallet.example/setup/set_1', $request->customer->reference ?? 'cus_1')),
            $request instanceof FetchSavedMethods => $request->setResult([new SavedMethod('pm_1', $request->customer, 'card', 'Visa •••• 4242', PaymentMethod::CARD, 'visa', '4242', 12, 2030)]),
            $request instanceof RemoveSavedMethod => $request->setResult('cus_1' === $request->customer),
            $request instanceof Purchase => $request->setResult(new Transaction('wallet', 'pay_1', null !== $request->payment->savedMethod ? Status::PAID : Status::PENDING, $request->payment->amount, null === $request->payment->savedMethod ? 'https://wallet.example/pay' : null)),
            default => null,
        };
    }
}

/** A provider whose page saves the method by itself: SetupMethod without CompleteMethodSetup. */
final class SetupOnlyAction implements ActionInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof SetupMethod;
    }

    public function execute(Request $request): void
    {
        $request->setResult(new MethodSetup('setup-only', 'set_1', 'https://wallet.example/setup/set_1'));
    }
}

/** The wallet behind a policy of one completion a minute. */
final class WalletFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        WalletAction::$completions = 0;
        $config->defaults([
            'omnitrade.factory_name' => 'wallet',
            'omnitrade.factory_title' => 'Wallet',
            'omnitrade.policy' => UsagePolicy::fromArray(['provider' => 'wallet', 'rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['completeMethodSetup']]]]),
            'omnitrade.action.wallet' => new WalletAction(),
        ]);
    }
}

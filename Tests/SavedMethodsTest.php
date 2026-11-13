<?php

namespace Omnitrade\Tests;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Gateway;
use Omnitrade\Model\Customer;
use Omnitrade\Model\MethodSetup;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Model\SavedMethod;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\Request\FetchSavedMethods;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\RemoveSavedMethod;
use Omnitrade\Request\Request;
use Omnitrade\Request\SetupMethod;
use PHPUnit\Framework\TestCase;

/** Saved payment methods (1.1): requests without a shortcut, answered by the action that supports them; Payment::$savedMethod. */
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
    public function supports(Request $request): bool
    {
        return $request instanceof SetupMethod || $request instanceof FetchSavedMethods || $request instanceof RemoveSavedMethod || $request instanceof Purchase;
    }

    public function execute(Request $request): void
    {
        match (true) {
            $request instanceof SetupMethod => $request->setResult(new MethodSetup('wallet', 'set_1', 'https://wallet.example/setup/set_1', $request->customer->reference ?? 'cus_1')),
            $request instanceof FetchSavedMethods => $request->setResult([new SavedMethod('pm_1', $request->customer, 'card', 'Visa •••• 4242', PaymentMethod::CARD, 'visa', '4242', 12, 2030)]),
            $request instanceof RemoveSavedMethod => $request->setResult('cus_1' === $request->customer),
            $request instanceof Purchase => $request->setResult(new Transaction('wallet', 'pay_1', null !== $request->payment->savedMethod ? Status::PAID : Status::PENDING, $request->payment->amount, null === $request->payment->savedMethod ? 'https://wallet.example/pay' : null)),
            default => null,
        };
    }
}

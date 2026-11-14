---
title: Payments
order: 31
---

# Payments

Every provider here takes the money on a page of its own: the gateway answers `purchase()` with a
`Transaction` PENDING and the page's address (`redirectUrl`), the application sends the buyer
there, and the provider says later - on the return page, through `fetch()`, or by webhook - what
became of it. None takes a card number on the server.

## A complete payment, from the order to the refund

The script runs as it is (`composer require omnitrade/stripe`): it answers in Stripe's place, with
answers shaped as Stripe's API gives them. With `HttpClient::create()` in place of the
`MockHttpClient` and a test key, the same lines open a real Checkout session.

```php
<?php // payments.php: a Checkout payment from the order to the refund, on answers shaped as Stripe gives them

require __DIR__.'/vendor/autoload.php';

use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Stripe\StripeGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// In an application: HttpClient::create(), and a test key. Here, Stripe's answers written by hand.
$session = ['id' => 'cs_test_a1', 'object' => 'checkout.session', 'amount_total' => 2750, 'currency' => 'eur', 'payment_method_types' => ['card'], 'created' => 1761400000, 'metadata' => ['order' => '1042']];
$sent = [];
$http = new MockHttpClient(static function (string $method, string $url, array $options) use ($session, &$sent): MockResponse {
    $call = $method.' '.parse_url($url, PHP_URL_PATH);
    $sent[] = [$call, $options['body'] ?? ''];
    $answer = match ($call) {
        'POST /v1/checkout/sessions' => $session + ['status' => 'open', 'payment_status' => 'unpaid', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_a1'],
        'GET /v1/checkout/sessions/cs_test_a1' => $session + ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_a1'],
        'GET /v1/payment_intents/pi_test_a1' => ['id' => 'pi_test_a1', 'object' => 'payment_intent', 'currency' => 'eur', 'latest_charge' => 'ch_test_a1'],
        'POST /v1/charges/ch_test_a1/refund' => ['id' => 're_test_a1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'eur'],
    };

    return new MockResponse(json_encode($answer), ['response_headers' => ['content-type' => 'application/json']]);
});

$stripe = (new StripeGatewayFactory($http))->create(['api_key' => 'sk_test_not_a_real_key', 'webhook_secret' => 'whsec_not_a_real_secret']);

// 1. The order: two bottles and the postage, as lines adding up to the amount - Checkout lists them.
$transaction = $stripe->purchase(new Payment(
    amount: Money::of(2750, 'EUR'),
    reference: 'ORDER-1042',
    description: 'Commande 1042',
    customer: new Customer(email: 'camille@example.org'),
    lines: [new Line('Margaux 2019', Money::of(1200, 'EUR'), 2), new Line('Colissimo', Money::of(350, 'EUR'))],
    returnUrl: 'https://shop.example/paid',
    idempotencyKey: 'ORDER-1042-attempt-1',
    metadata: ['order' => '1042'],
    locale: 'fr-FR',
));
echo $transaction->status->value, ' ', $transaction->reference, ' ', $transaction->redirectUrl, "\n";

// 2. The webhook Stripe sends once the buyer has paid, signed with the endpoint's secret.
$body = json_encode(['id' => 'evt_a1', 'type' => 'checkout.session.completed', 'data' => ['object' => $session + ['status' => 'complete', 'payment_status' => 'paid']]]);
$time = time();
$signature = sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$body, 'whsec_not_a_real_secret'));
$notification = $stripe->notify($body, ['Stripe-Signature' => $signature]);
echo $notification->event, ' ', $notification->reference, ' ', $notification->status->value, ' ', $notification->transaction->amount, "\n";

// 3. Where it stands, then 5,00 EUR given back.
echo $stripe->fetch('cs_test_a1')->status->value, "\n";
$refund = $stripe->refund('cs_test_a1', Money::of(500, 'EUR'), idempotencyKey: 'ORDER-1042-refund-1');
echo $refund->reference, ' ', $refund->amount, ' ', $refund->status->value, "\n\n";

foreach ($sent as [$call, $form]) {
    echo $call, "\n";
}
parse_str($sent[0][1], $form);
echo 'success_url: ', $form['success_url'], "\n";
foreach ($form['line_items'] as $line) {
    echo 'line: ', $line['quantity'], ' x ', $line['price_data']['product_data']['name'], ' ', $line['price_data']['unit_amount'], "\n";
}
```

```
$ php payments.php
pending cs_test_a1 https://checkout.stripe.com/c/pay/cs_test_a1
checkout.session.completed cs_test_a1 paid 27.50 EUR
paid
re_test_a1 5.00 EUR refunded

POST /v1/checkout/sessions
GET /v1/checkout/sessions/cs_test_a1
GET /v1/checkout/sessions/cs_test_a1
GET /v1/payment_intents/pi_test_a1
POST /v1/charges/ch_test_a1/refund
success_url: https://shop.example/paid?session={CHECKOUT_SESSION_ID}
line: 2 x Margaux 2019 1200
line: 1 x Colissimo 350
```

(run on 2026-10-25 in the harness image, PHP 8.4, from the workspace's checkouts)

The refund reads the session, then its payment intent, to find the charge it refunds: three
calls. Stripe's Checkout takes the money on its page, so there is no `capture()` here.

`omnitrade/stripe` sends `Payment::$shipping` as the Checkout session's shipping rate, on top of
the lines; a payment with a `discount` is one line of its amount there (Checkout takes a discount
only as a coupon made beforehand). The other providers send both as such.

## Purchase

```php
$transaction = $gateway->purchase(new Payment(Money::of(2750, 'EUR'), 'ORDER-1042', returnUrl: 'https://shop.example/paid'));
if ($transaction->isRedirect()) {
    // keep $transaction->reference with the order, then:
    header('Location: '.$transaction->redirectUrl);
}
```

| Provider | What `purchase()` makes | Answer | Back to the shop |
|---|---|---|---|
| Stripe | a Checkout session, `mode=payment` | PENDING, the session's `url` | `returnUrl?session={CHECKOUT_SESSION_ID}`; `cancelUrl`, else `returnUrl?cancel=1` |
| PayPal | an Orders v2 order, intent `CAPTURE` | PENDING, its `payer-action` (or `approve`) link | `returnUrl?token=<order id>`, then `capture()` |
| Shopify | a draft order | PENDING, its `invoiceUrl` | nobody comes back: `fetch()` or the `orders/paid` webhook |
| WooCommerce | an order, `status: pending`, `set_paid: false` | PENDING, its `payment_url` | nobody comes back: `fetch()` or the `order.updated` webhook |

Stripe and PayPal need `returnUrl`: without it they throw a `ProviderException` before any call.
The lines are listed when `Payment::linesAddUp()` (at Stripe, with no discount, above); otherwise the
amount is one line named by the description, else the reference. Which other fields each provider
sends: [configuration](configuration.md#what-each-provider-reads-of-a-payment).

## Authorize, capture, void

Only PayPal answers `authorize()` (`supports(Authorize::class)` is false elsewhere).

```php
$transaction = $paypal->authorize($payment);          // an order with intent AUTHORIZE: PENDING, PayPal's page
// the buyer approves it and comes back to returnUrl?token=<order id>
$paid = $paypal->capture($transaction->reference);     // PAID: authorized, then captured
$paid = $paypal->capture($transaction->reference, Money::of(1000, 'EUR'));   // or part of it (final_capture)
```

`capture()` reads the order first: an order already captured (or refunded) is answered as it is, so
the return page and the webhook may both call it. An order the buyer has not approved is a
`ProviderException` (`The order is PAYER_ACTION_REQUIRED: the buyer has not approved it.`). For an
order made with intent `AUTHORIZE`, `capture()` asks PayPal for the authorization
(`POST /v2/checkout/orders/{id}/authorize`) when the order holds none yet, then captures it;
`fetch()` and `void()` ask for it too, so an approved order reads AUTHORIZED and can be voided.

`void($reference)` takes the order's id or an authorization's id and lets the authorization go;
the answer is the order read again (CANCELLED once PayPal voided it).

## Refund

```php
$refund = $gateway->refund($reference);                                   // all of it
$refund = $gateway->refund($reference, Money::of(500, 'EUR'), idempotencyKey: 'ORDER-1042-refund-1', reason: 'One bottle broken');
$refund->status;   // REFUNDED, or PENDING while the provider processes it
```

| Provider | `$reference` | Call | Notes |
|---|---|---|---|
| Stripe | a session (`cs_...`), a payment intent (`pi_...`) or a charge (`ch_...`) | omnipay's refund, `POST /v1/charges/{charge}/refund`; without an amount `POST /v1/refunds` with the charge (all of it) | the session and its intent are read to find the charge; `reason` is not sent; `Idempotency-Key` |
| PayPal | an order (its first capture) or a capture id | `POST /v2/payments/captures/{id}/refund` | `reason` as `note_to_payer` (255 characters); `PayPal-Request-Id: <capture>-refund-<key>` |
| WooCommerce | an order id | `POST /orders/{id}/refunds`, `api_refund: true` | through the shop's payment gateway; no amount: the order's total; the refund is always REFUNDED |
| Shopify | - | not supported | Shopify's admin refunds its orders |

## Fetch

`fetch($reference)` reads the transaction as the provider has it now: the return page and a
webhook that did not arrive both rely on it.

| Provider | Read | Status |
|---|---|---|
| Stripe | the Checkout session | `payment_status` `paid` or `no_payment_required`: PAID; `status` `expired`: EXPIRED; else PENDING with the session's `url`; refunded (the payment intent's latest charge, expanded): REFUNDED or PARTIALLY_REFUNDED |
| PayPal | the order | `COMPLETED` with a capture: PAID, or the capture's `REFUNDED`, `PARTIALLY_REFUNDED`, `DECLINED`/`FAILED` (REFUSED), `PENDING`; `COMPLETED` with an authorization only: AUTHORIZED, or its `VOIDED` (CANCELLED), `EXPIRED`, `DENIED` (REFUSED); `VOIDED`: CANCELLED; else PENDING |
| Shopify | the draft order and the order it became | completed and paid: PAID (refunded: REFUNDED, PARTIALLY_REFUNDED; voided: CANCELLED); deleted: CANCELLED; else PENDING with the invoice URL |
| WooCommerce | the order | `processing`, `completed`: PAID (REFUNDED or PARTIALLY_REFUNDED by its refunds); `refunded`: REFUNDED; `cancelled`: CANCELLED; `failed`: REFUSED; else (`pending`, `on-hold`, `checkout-draft`) PENDING with its `payment_url` |

## Payment methods

`paymentMethods(?Money $for, ?string $country)` lists what the provider can take. Stripe narrows it
by the amount's currency (its euro-only methods), PayPal its Pay Later by the country and the
currency; WooCommerce lists the gateways the shop enabled whatever the amount, the shop choosing.

| Provider | List |
|---|---|
| Stripe | the `payment_methods` option when set (`card`, `sepa_debit`, `klarna`... labelled from a table of eleven known codes), else `card`, `apple_pay`, `google_pay`, `link`; `sepa_debit`, `bancontact`, `ideal`, `eps` left out of an amount not in euros |
| PayPal | `paypal` (wallet), `card` (guest checkout), and `paylater` (buy now, pay later) in AU, FR, DE, IT, ES, GB, US in their currency |
| WooCommerce | the shop's payment gateways enabled (`GET /payment_gateways`), their kind guessed from their id (`stripe`/`card`: card, `paypal`/`apple`/`google`: wallet, `bacs`/`sepa`/`bank`: bank, `klarna`/`afterpay`: buy now pay later) |

## Idempotency

`Payment::$idempotencyKey`, and the `$idempotencyKey` argument of `capture()` and `refund()`, make
the same operation asked twice happen once, where the provider honours it. Give one per attempt
and keep it with the attempt.

| Provider | How it is sent |
|---|---|
| Stripe | `Idempotency-Key` on the session's creation and on the refund |
| PayPal | `PayPal-Request-Id`: `<reference>-<key>` (purchase, authorize), `<order>-capture-<key>`, `<order>-authorize`, `<capture>-refund-<key>`; the purchase's `invoice_id` is `<reference>-<key>` too |
| Shopify, WooCommerce | not sent |

## Saved methods, charged without a page (1.1, completed 1.2)

A buyer who comes back often saves a method once, on the provider's page, and is charged later
with no page at all. The site keeps the provider's references - its customer, the method - and
never a card number.

```php
use Omnitrade\Model\Customer;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Request\CompleteMethodSetup;
use Omnitrade\Request\FetchSavedMethods;
use Omnitrade\Request\RemoveSavedMethod;
use Omnitrade\Request\SetupMethod;

// 1. Saved on the provider's page; keep $setup->customer with the buyer's account, and
//    $setup->reference with something the return URL names (a token of yours in it).
$setup = $gateway->execute(new SetupMethod(new Customer('alice@example.org', 'Alice'), 'https://shop.example/account/saved?setup=k3y', idempotencyKey: 'setup-42'))->getSetup();
header('Location: '.$setup->redirectUrl);

// 1b. Back on the return URL: completed where the gateway supports it (1.2) - the same code
//     for every provider; PayPal needs it before the method is listed, Stripe answers it as saved.
if ($gateway->supports(CompleteMethodSetup::class)) {
    $saved = $gateway->execute(new CompleteMethodSetup($setup->reference, $setup->customer))->getMethod();
}

// 2. Listed as the provider has them now (an expired card says so: isExpired()).
$methods = $gateway->execute(new FetchSavedMethods($setup->customer))->getMethods();

// 3. Charged at once. PAID, REFUSED (the decline code in providerCode), or PENDING with the bank's
//    page in redirectUrl when it asks its buyer (3-D Secure): notify() or fetch() says how it ended.
$paid = $gateway->purchase(new Payment(
    Money::of(1990, 'EUR'), 'ORDER-7',
    customer: new Customer(reference: $setup->customer),
    returnUrl: 'https://shop.example/orders/7',
    idempotencyKey: 'order-7-attempt-1',
    savedMethod: $methods[0]->reference,
));

// 4. Removed: only a method of that customer.
$gateway->execute(new RemoveSavedMethod($setup->customer, $methods[0]->reference));
```

`supports(SetupMethod::class)` says whether a provider can; omnitrade/stripe 1.1 does (Checkout in
setup mode, PaymentIntents off-session), omnitrade/paypal 1.2 too (Vault v3). Give the purchase an
idempotency key per attempt and keep it: the same charge asked twice is made once.

`CompleteMethodSetup` (1.2) answers the `SavedMethod` the setup saved. A provider that needs the
step supports `SetupMethod` only along with it, so a host that asks it whenever it is supported
works with every provider. It refuses with a `ProviderException` whose `providerCode` is
`CompleteMethodSetup::NOT_THIS_CUSTOMER` a setup that is not the named customer's, and
`CompleteMethodSetup::NOT_COMPLETED` one the buyer did not finish (cancelled, still open, expired):
nothing is saved, the buyer starts again with a new `SetupMethod`. Asked twice, it answers the same
method. The buyer who closes the page before the redirect never reaches the return URL: a host that
keeps its pending setups can complete them later (when it lists the methods, say), since the
completion is idempotent.


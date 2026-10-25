---
title: Payments
order: 31
---

## Payments

Every provider here takes the money on a page of its own: the gateway answers `purchase()` with a
`Transaction` PENDING and the page's address (`redirectUrl`), the application sends the buyer
there, and the provider says later - on the return page, through `fetch()`, or by webhook - what
became of it. None takes a card number on the server.

### A complete payment, from the order to the refund

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

> [!WARNING]
> `omnitrade/stripe` lists the lines on the Checkout page without `Payment::$shipping` and
> `Payment::$discount` (it sends the lines only, when they add up with them): a payment whose
> lines add up to the amount through a shipping or a discount is charged the lines' total. Give
> the postage as a line, and a discount in the lines' prices, as above, or give no lines (the
> amount is then one line). The other providers send both.

### Purchase

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
The lines are listed when `Payment::linesAddUp()` (with the warning above for Stripe); otherwise the
amount is one line named by the description, else the reference. Which other fields each provider
sends: [configuration](configuration.md#what-each-provider-reads-of-a-payment).

### Authorize, capture, void

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
(`POST /v2/checkout/orders/{id}/authorize`) when the order holds none yet, then captures it.

`void($reference)` takes the order's id or an authorization's id and lets the authorization go;
the answer is the order read again (CANCELLED once PayPal voided it).

> [!WARNING]
> `omnitrade/paypal` asks for the authorization only inside `capture()`. After `authorize()` and
> the buyer's approval, the order holds no authorization yet: `fetch()` reads it PENDING, and
> `void()` throws `The order holds no authorization to void.` Letting go of an approved order that
> was never captured is done in PayPal's dashboard, or by leaving it to expire.

### Refund

```php
$refund = $gateway->refund($reference);                                   // all of it (not at Stripe: see below)
$refund = $gateway->refund($reference, Money::of(500, 'EUR'), idempotencyKey: 'ORDER-1042-refund-1', reason: 'One bottle broken');
$refund->status;   // REFUNDED, or PENDING while the provider processes it
```

| Provider | `$reference` | Call | Notes |
|---|---|---|---|
| Stripe | a session (`cs_...`), a payment intent (`pi_...`) or a charge (`ch_...`) | omnipay's refund, `POST /v1/charges/{charge}/refund` | the session and its intent are read to find the charge; `reason` is not sent; `Idempotency-Key`; an amount is required (below) |
| PayPal | an order (its first capture) or a capture id | `POST /v2/payments/captures/{id}/refund` | `reason` as `note_to_payer` (255 characters); `PayPal-Request-Id: <capture>-refund-<key>` |
| WooCommerce | an order id | `POST /orders/{id}/refunds`, `api_refund: true` | through the shop's payment gateway; no amount: the order's total; the refund is always REFUNDED |
| Shopify | - | not supported | Shopify's admin refunds its orders |

> [!WARNING]
> `omnitrade/stripe` needs the amount: omnipay's refund request requires one, and
> `refund($reference)` without it throws omnipay's `InvalidRequestException` (`The amount
> parameter is required`) before any call - not a `ProviderException`. Pass the transaction's
> amount to refund all of it.

### Fetch

`fetch($reference)` reads the transaction as the provider has it now: the return page and a
webhook that did not arrive both rely on it.

| Provider | Read | Status |
|---|---|---|
| Stripe | the Checkout session | `payment_status` `paid` or `no_payment_required`: PAID; `status` `expired`: EXPIRED; else PENDING with the session's `url`. A refund is read only from an expanded payment intent, which the fetch does not ask for: a refunded session reads PAID |
| PayPal | the order | `COMPLETED` with a capture: PAID, or the capture's `REFUNDED`, `PARTIALLY_REFUNDED`, `DECLINED`/`FAILED` (REFUSED), `PENDING`; `COMPLETED` with an authorization only: AUTHORIZED, or its `VOIDED` (CANCELLED), `EXPIRED`, `DENIED` (REFUSED); `VOIDED`: CANCELLED; else PENDING |
| Shopify | the draft order and the order it became | completed and paid: PAID (refunded: REFUNDED, PARTIALLY_REFUNDED; voided: CANCELLED); deleted: CANCELLED; else PENDING with the invoice URL |
| WooCommerce | the order | `processing`, `completed`: PAID (REFUNDED or PARTIALLY_REFUNDED by its refunds); `refunded`: REFUNDED; `cancelled`: CANCELLED; `failed`: REFUSED; else (`pending`, `on-hold`, `checkout-draft`) PENDING with its `payment_url` |

### Payment methods

`paymentMethods(?Money $for, ?string $country)` lists what the provider can take. No provider reads
`$for` or `$country` yet: the list is the same whatever the amount.

| Provider | List |
|---|---|
| Stripe | the `payment_methods` option when set (`card`, `sepa_debit`, `klarna`... labelled from a table of eleven known codes), else `card`, `apple_pay`, `google_pay`, `link` |
| PayPal | always `paypal` (wallet), `card` (guest checkout), `paylater` (buy now, pay later) |
| WooCommerce | the shop's payment gateways enabled (`GET /payment_gateways`), their kind guessed from their id (`stripe`/`card`: card, `paypal`/`apple`/`google`: wallet, `bacs`/`sepa`/`bank`: bank, `klarna`/`afterpay`: buy now pay later) |

### Idempotency

`Payment::$idempotencyKey`, and the `$idempotencyKey` argument of `capture()` and `refund()`, make
the same operation asked twice happen once, where the provider honours it. Give one per attempt
and keep it with the attempt.

| Provider | How it is sent |
|---|---|
| Stripe | `Idempotency-Key` on the session's creation and on the refund |
| PayPal | `PayPal-Request-Id`: `<reference>-<key>` (purchase, authorize), `<order>-capture-<key>`, `<capture>-refund-<key>`; the purchase's `invoice_id` is `<reference>-<key>` too |
| Shopify, WooCommerce | not sent |

---
title: Webhooks
order: 32
---

# Webhooks

**Webhooks are optional.** A payment can always be read from the provider: keep the reference
`purchase()` gave (`Transaction::$reference`), and `fetch()` it again on a schedule until it is
paid, refused or given up - omnibase/estate's `estate:payment:check` does this every 15 minutes
for rents. The webhook only records a payment sooner. A development machine, which no provider can
reach, needs no tunnel nor `stripe listen`: leave `webhook_secret` empty there.

`notify(string $body, array $headers = [])` reads what a provider sends to the shop's webhook
endpoint: it checks the signature first, then reads the event as one `Notification`. Hand it the
**raw** body (re-encoding JSON breaks the signature) and the request's headers; a header is found
whatever its case, and a list of values gives its first one, so PHP's `getallheaders()`, Symfony's
`$request->headers->all()` and Laravel's `$request->headers->all()` all do.

```php
use Omnitrade\Exception\InvalidNotificationException;

try {
    $notification = $gateway->notify(file_get_contents('php://input'), getallheaders());
} catch (InvalidNotificationException) {
    http_response_code(400);   // not the provider's, or too old: ignore it
    exit;
}

if (null !== $notification->id && $events->seen($notification->id)) {
    http_response_code(204);   // delivered twice: handled once
    exit;
}

match (true) {
    $notification->isCatalogue() => $catalogue->update($notification),            // $product, $stocks
    $notification->isAccount() => $accounts->update($notification->account),
    $notification->isSubscription() => $subscriptions->update($notification->subscription),
    null !== $notification->status => $orders->settle($notification->reference, $notification->status),
    default => null,                                                                // an event to ignore, or to read from $raw
};
http_response_code(204);
```

A webhook can come before the buyer is back, after, twice, or never: settle the order from
whichever comes first (the return page's `fetch()` or the webhook), and keep the event's `id`.

`$notification->status` is what the event means for the transaction, when it means anything, and
`$notification->reference` the transaction it concerns, by the provider's id - the reference
`purchase()` answered (for Shopify, the draft order). The [payments](usage-payments.md#a-complete-payment-from-the-order-to-the-refund)
example signs and reads a Stripe event, offline.

## What each provider checks

| Provider | Signature | Without the secret |
|---|---|---|
| Stripe | `Stripe-Signature: t=<timestamp>,v1=<hmac>`: HMAC-SHA256 of `<timestamp>.<body>` with `webhook_secret`, any `v1` matching; the timestamp within 300 seconds | `InvalidNotificationException` (`No webhook secret: ...`) |
| PayPal | PayPal checks it itself: the `PAYPAL-TRANSMISSION-ID`, `-TIME`, `-SIG`, `PAYPAL-CERT-URL` and `PAYPAL-AUTH-ALGO` headers and the event are posted to `POST /v1/notifications/verify-webhook-signature` with `webhook_id`; anything but `verification_status: SUCCESS` is refused | `InvalidNotificationException` (`No webhook_id: ...`) |
| Shopify | `X-Shopify-Hmac-Sha256`: base64 of the HMAC-SHA256 of the body with `webhook_secret`; and `X-Shopify-Shop-Domain`, when sent, must be the configured shop | `InvalidNotificationException` |
| WooCommerce | `X-WC-Webhook-Signature`: base64 of the HMAC-SHA256 of the body with `webhook_secret` | `InvalidNotificationException` |

A signature that holds on a body that is not JSON (or not the provider's event) is a
`ProviderException`, not an `InvalidNotificationException`. Stripe's signature is the only one
with a timestamp; Shopify and WooCommerce resend a delivery with the same id
(`X-Shopify-Webhook-Id`, `X-WC-Webhook-Delivery-ID`), kept in `Notification::$id`.

## The events each provider reads

| Provider | Event | `status` | Also |
|---|---|---|---|
| Stripe | `checkout.session.completed`, `checkout.session.async_payment_succeeded` | PAID when the session's `payment_status` is `paid`, else PENDING | `transaction`: the session |
| Stripe | `checkout.session.expired` | EXPIRED | `transaction` |
| Stripe | `checkout.session.async_payment_failed` | REFUSED | `transaction` |
| Stripe | `account.updated` | - | `account` |
| Stripe | `customer.subscription.*` | - | `subscription` |
| Stripe | `product.created`, `product.updated`; `price.*`; `product.deleted` | - | `product` (read with its prices: one or two calls); `null` for a deletion |
| PayPal | `CHECKOUT.ORDER.APPROVED` | PENDING (the money comes with `capture()`) | `transaction`: the order |
| PayPal | `CHECKOUT.ORDER.COMPLETED`, `PAYMENT.CAPTURE.COMPLETED` | PAID | `transaction` for an order event |
| PayPal | `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.DECLINED` | REFUSED | |
| PayPal | `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED` | REFUNDED | |
| PayPal | `PAYMENT.AUTHORIZATION.CREATED` | AUTHORIZED | |
| PayPal | `PAYMENT.AUTHORIZATION.VOIDED`, `CHECKOUT.ORDER.VOIDED` | CANCELLED | |
| Shopify | `orders/paid` | PAID | `reference`: the draft order the sale began with, else the order's gid |
| Shopify | `orders/cancelled` | CANCELLED | |
| Shopify | `refunds/create` | REFUNDED (a partial refund too) | `reference`: the refund's gid |
| Shopify | `products/create`, `products/update`; `products/delete` | - | `product`; `null` for a deletion |
| Shopify | `inventory_levels/update` | - | `stocks`: one level at one location, by inventory item |
| WooCommerce | `order.created`, `order.updated` | the order's status, as `fetch()` reads it | `transaction`: the order |
| WooCommerce | `order.deleted` | - | |
| WooCommerce | `product.created`, `product.updated`, `product.restored`; `product.deleted` | - | `product` (variations read along); `null` for a deletion |

Any other event is handed back with no status, its body in `$raw`. For a PayPal capture event,
`reference` is the order's id from the capture's `supplementary_data.related_ids.order_id`. For a
Shopify order, the merchant's reference (the `Payment::$reference`) travels as the note attribute
`omnitrade_reference` and is given back in `$raw['merchant_reference']`. Each provider's manual
lists the events to subscribe to, and the catalogue's in [catalogue](catalogue.md#webhooks).

The web and Amazon providers receive no webhooks: `supports(Notify::class)` is false.

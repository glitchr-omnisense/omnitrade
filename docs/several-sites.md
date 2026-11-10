---
title: Several sites in one sandbox
order: 36
---

# Several sites in one sandbox

A provider account limits its test environments: Stripe gives an account at most five
sandboxes. Demo sites therefore share one sandbox, and each tells its own objects apart. Three
things make that work, one per site: a key of its own, a webhook endpoint of its own, and a
`site` tag on everything it creates.

## The tag

Each gateway takes a `tags` option, set once in its configuration
([Configuration](configuration.md#the-registry)):

```yaml
# config/packages/omnitrade.yaml
omnitrade:
    gateways:
        stripe:
            factory: stripe
            options:
                api_key: '%env(STRIPE_API_KEY)%'
                webhook_secret: '%env(STRIPE_WEBHOOK_SECRET)%'
                tags: { site: solstay }
```

In plain PHP the same map goes in the gateway's options:
`'options' => ['api_key' => '...', 'tags' => ['site' => 'solstay']]`.

The tags go under the metadata of every create call. When a call sends metadata of its own
(`Payment::$metadata`, `createAccount(metadata: ...)`), the call's value wins on a key both
have. `site` is the exception: the configuration's value always wins, so a payment cannot pass
for another site's. Without the option nothing changes: the requests are what they were.

| Provider | Where the tags go | What a webhook gives back |
|---|---|---|
| omnitrade/stripe | `metadata` on the Checkout Session, `payment_intent_data.metadata` (the payment intent), `subscription_data.metadata` (a subscription), the refund (`POST /v1/refunds` and `/v1/charges/{charge}/refund`), the connected account, the webhook endpoint | the event object's `metadata`; for an invoice, its subscription's |
| omnitrade/paypal | only `site`: the purchase unit's `reference_id` (`default` without a site) and the refund's `custom_id`. PayPal keeps no metadata map, and the order's `custom_id` already carries the merchant's reference | `site` on the `CHECKOUT.ORDER.*` events and on `PAYMENT.CAPTURE.REFUNDED`; none on the other capture and authorization events |
| omnitrade/shopify | the draft order's custom attributes (the order's `note_attributes`), and a `site:<name>` tag beside `draft_order_tags` | the order's `note_attributes`, else its `site:` tag |
| omnitrade/woocommerce | the order's and the refund's `meta_data` | the order's `meta_data` (without the merchant's reference and the `_` fields) |

What is not tagged:

- **Stripe customers made by Checkout.** A Checkout Session has no parameter that sets the
  metadata of the customer it creates (subscription mode always makes one). The session and the
  subscription carry the tag, and the customer is reached through them.
- **PayPal**: the tags other than `site`, and the capture and authorization events (their
  resource carries the order's `custom_id`, the merchant's reference).
- **Shopify**: the `refunds/create` event, whose payload has neither attributes nor tags.
- **omnitrade/web and omnitrade/amazon** create nothing at a provider.

## Filtering the events

Each site registers its own endpoint (below), so an event normally reaches only its site. If
one reaches the wrong one anyway (an endpoint copied, a URL reused), the site skips it:

```php
$notification = $gateway->notify($body, $headers);
if ($notification->isFromAnotherSite('solstay')) {
    return new Response('', 204);   // another site's: acknowledged, nothing done
}
```

`isFromAnotherSite()` is true only when the object names a site and it is another. An object with
no tag (made in the dashboard, or a PayPal capture event) is not filtered. `site()` and `tags` give
the values themselves.

## A key per site

Each site gets a **restricted key** named after it (`solstay`), with only the rights its calls
need. The rights below use the names of Stripe's restricted key editor. Check them there.

| The gateway's call | Stripe resources |
|---|---|
| `purchase()`, `subscribe()` | Checkout Sessions: write |
| `fetch()`, `refund()` | Checkout Sessions: read, PaymentIntents: read, Charges and Refunds: write |
| `fetchProducts()`, `fetchProduct()` | Products: read, Prices: read |
| `createAccount()`, `accountLink()`, `fetchAccount()` | Connect: Accounts (write) |
| `fetchSubscription()`, `cancelSubscription()` | Subscriptions: write |
| `subscriptionPortal()` | Customer portal: write |
| `RegisterWebhook`, `FetchWebhooks`, `DeleteWebhook` | Webhook Endpoints: write |

A restricted key (`rk_test_...`) goes in `api_key` like a secret key. A call it has no right to is
refused by Stripe (a `ProviderException`, HTTP 403).

## A webhook endpoint per site

Each site has its own endpoint at its own URL, with its own signing secret (`whsec_...`) in its
`webhook_secret`. Register it from the application (`Request\RegisterWebhook`,
[Webhooks](usage-webhooks.md)): with a `site` tag the endpoint carries it in its metadata too, so
the dashboard's list of endpoints says whose each one is. A secret checks only its own endpoint's
events, which already keeps the sites apart. The tag is the second check.

## Finding a site's objects

- Stripe's Search API filters on metadata: `GET /v1/payment_intents/search?query=metadata['site']:'solstay'`.
  The same works for charges, subscriptions and invoices. Search is eventually consistent (about a
  minute) and limited to 20 reads a second. In the dashboard, a payment's page shows its metadata.
  This manual does not claim that the dashboard's search box filters on a metadata key.
- Shopify: the admin's order list filters on the tag (`tag:'site:solstay'`).
- WooCommerce: the order's custom fields show the `site` meta.
- PayPal: the order's `reference_id` shows in the order's details (API), not in a search.

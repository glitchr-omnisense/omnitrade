---
title: Configuration reference
order: 40
---

# Configuration reference

## The registry

`new Registry(iterable $factories, array $config)`: the factories installed, and the gateways by
name. Each gateway is a factory's name and its options:

```php
[
    'card' => ['factory' => 'stripe', 'options' => ['api_key' => '...', 'webhook_secret' => '...']],
    'woo' => ['factory' => 'woocommerce', 'options' => ['url' => '...', 'consumer_key' => '...', 'consumer_secret' => '...']],
]
```

| Key | Type | Required | |
|---|---|---|---|
| `<name>` | string | - | the gateway's name: `Registry::get('<name>')`, `GatewayInterface $<name>` in Symfony |
| `<name>.factory` | string | yes | a factory's `getName()` |
| `<name>.options` | map | no (`[]`) | handed to the factory's `create()` |

Several gateways may use one factory (two Stripe accounts, a live and a sandbox PayPal). In
Symfony the same tree sits under `omnitrade.gateways` ([Symfony](symfony.md#configuration)).

Options are read when the gateway is built, not before: a required option missing, `''` or `[]`
is an `InvalidConfigException` naming every missing one. An option no code reads is kept in the
`Config` and ignored. Every key of the `Config` can be given as an option, the reserved ones
included: `omnitrade.action.<name>` replaces a provider's action ([Concepts](concepts.md#factory-and-config)).

## omnitrade/stripe

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | - | yes | a secret key (`sk_live_...`, `sk_test_...`) or a restricted key with what the gateway calls |
| `webhook_secret` | ?string | `null` | for `notify()` | the endpoint's signing secret (`whsec_...`); without it `notify()` refuses every event |
| `adaptive_pricing` | bool | `false` | no | `true`: Stripe may offer the buyer their own currency on Checkout (`adaptive_pricing[enabled]`) |
| `payment_methods` | list of strings | `[]` | no | the Checkout methods to insist on (`['card', 'sepa_debit']`, sent as `payment_method_types`) and what `paymentMethods()` lists; `[]`: Stripe's choice per account, country and currency |
| `active_only` | bool | `false` | no | `true`: `fetchProducts()` lists the active products only |

Plain REST calls (catalogue, Connect, subscriptions) have a 30-second timeout; omnipay's calls
(Checkout, refunds) use the HTTP client's.

## omnitrade/paypal

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | the REST app's client id |
| `secret` | string | - | yes | the REST app's secret |
| `sandbox` | bool | `false` | no | `true`: `https://api-m.sandbox.paypal.com` with sandbox credentials; else `https://api-m.paypal.com` |
| `webhook_id` | ?string | `null` | for `notify()` | the webhook's id in the developer dashboard, sent to PayPal's verification |
| `timeout` | int | `15` | no | seconds, for every call |

## omnitrade/shopify

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `shop_domain` | string | - | yes | `example.myshopify.com` |
| `admin_token` | string | - | yes | a custom app's Admin API access token (`shpat_...`) |
| `webhook_secret` | ?string | `null` | for `notify()` | the app's API secret, which signs its webhooks |
| `storefront_token` | ?string | `null` | no | a Storefront API token; the API client is built with it, but no action calls the Storefront API |
| `api_version` | string | `2026-07` | no | the Admin API version in `https://<shop>/admin/api/<version>/graphql.json` |
| `timeout` | int | `15` | no | seconds |
| `draft_order_tags` | list of strings | `[]` | no | tags put on the draft orders `purchase()` creates |
| `currency` | ?string | `null` | no | the currency the catalogue's prices are read in; `null`: the shop's (`shop { currencyCode }`). A label: nothing is converted |
| `retry_delay` | float | `1` | no | seconds before retrying a 429 or a 5xx, doubled at each attempt (3 attempts); a 429's `Retry-After` prevails, capped at 60 |

## omnitrade/woocommerce

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `url` | string | - | yes | the shop's address (`https://shop.example`); the API is `<url>/wp-json/wc/v3/` |
| `consumer_key` | string | - | yes | a REST API key (`ck_...`), HTTP basic authentication |
| `consumer_secret` | string | - | yes | its secret (`cs_...`) |
| `webhook_secret` | ?string | `null` | for `notify()` | the secret set on the shop's webhooks |
| `timeout` | int | `15` | no | seconds |
| `currency` | string | `EUR` | no | the store's currency, for the catalogue's prices (bare decimals in the API); orders carry their own |
| `weight_unit` | string | `kg` | no | the store's weight unit: `kg`, `g`, `lbs`, `oz`; weights are given in grams |
| `product_status` | string | `any` | no | what `fetchProducts()` lists: `any` (drafts, pending and private ones too), or `publish` |

## omnitrade/web

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `user_agent` | string | `Mozilla/5.0 (compatible; Omnitrade/1.0; +https://github.com/glitchr-omnisense/omnitrade-web)` | no | what the shop sees |
| `timeout` | int | `10` | no | seconds |
| `max_bytes` | int | `3000000` | no | a longer page is cut there |
| `allow_private` | bool | `false` | no | `true`: addresses of the local network may be read (an intranet, tests) |
| `currency` | string | `EUR` | no | of a price found without one |
| `affiliate` | map host => map of query parameters | `[]` | no | for `affiliateLink()`: `{ 'amazon.fr': { tag: 'mysite-21' } }`; a host matches itself and its subdomains |

## omnitrade/amazon

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | ?string | `null` | for the reads | the Creators API credentials; without both, `fetchProduct()` and `fetchProducts()` are not supported |
| `client_secret` | ?string | `null` | for the reads | |
| `partner_tag` | ?string | `null` | for links | the Associates tag (`mysite-21`), in every request and every link; without it `affiliateLink()` answers `null` |
| `marketplace` | string | `www.amazon.fr` | no | the store read, sent in the body and as `x-marketplace`; the host of links to a bare ASIN |
| `credential_version` | string | `3.2` | no | the token endpoint: `3.1` `https://api.amazon.com/auth/o2/token`, `3.2` `https://api.amazon.co.uk/auth/o2/token`, `3.3` `https://api.amazon.co.jp/auth/o2/token`; another value without `token_url` is an `InvalidConfigException` when the gateway is built |
| `token_url` | ?string | `null` | no | another token endpoint, in place of `credential_version`'s |
| `api_url` | string | `https://creatorsapi.amazon/catalog/v1` | no | |
| `timeout` | int | `10` | no | seconds |

No option is required: a gateway with only `partner_tag` writes affiliate links.

## What each provider reads of a payment

`Payment` carries more than any one provider sends. What each `purchase()` sends (`authorize()` the
same at PayPal), from the code:

| Field | Stripe | PayPal | Shopify | WooCommerce |
|---|---|---|---|---|
| `amount` | yes (`unit_amount` of the single line) | `amount.value` | the single line's price, `presentmentCurrencyCode` | the single item's total, `currency` |
| `reference` | `client_reference_id` | `custom_id` (127), `PayPal-Request-Id` | custom attribute `omnitrade_reference` | meta `omnitrade_reference` |
| `description` | the single line's name | `description` (127) | the draft order's `note`, the single line's title | the single item's name |
| `customer` | `email` (`customer_email`) | `email` (`payment_source.paypal.email_address`) | `email` | `billing`: email, name (first word / the rest), phone, street (two lines), postal code, city, country |
| `lines` | name, image, unit amount, quantity | name (127), quantity, unit amount, `sku`, `physical` (category `PHYSICAL_GOODS` / `DIGITAL_GOODS`) | a variant gid in `reference` names the variant, else a custom line (title, `physical` as `requiresShipping`, `sku`) | name, quantity, total; a numeric `reference` as `product_id` |
| `shipping` | `shipping_options[0][shipping_rate_data]`, a fixed amount (when the lines are listed) | `breakdown.shipping` | `shippingLine` | a `flat_rate` shipping line |
| `discount` | not sent as such: the amount is one line | `breakdown.discount` | `appliedDiscount`, fixed amount | a negative fee line |
| `returnUrl` | required, `success_url` | required, `experience_context.return_url` | not used | not used |
| `cancelUrl` | `cancel_url` (else `returnUrl?cancel=1`) | `experience_context.cancel_url` | not used | not used |
| `idempotencyKey` | `Idempotency-Key` | `PayPal-Request-Id`, `invoice_id` | not used | not used |
| `method` | `payment_method_types` | not used | not used | not used |
| `notice` | `custom_text.submit.message`, the payment intent's description | not used | not used | `customer_note` |
| `metadata` | `metadata` (strings) | not used | custom attributes | `meta_data` |
| `locale` | its first two letters | `experience_context.locale` (`_` made `-`) | not used | not used |
| `destination`, `applicationFee` | a destination charge ([connect](connect.md)) | not used | not used | not used |

Lines are sent only when `Payment::linesAddUp()`; otherwise the amount is one line. PayPal also
sends `user_action: PAY_NOW` and `shipping_preference: NO_SHIPPING`.

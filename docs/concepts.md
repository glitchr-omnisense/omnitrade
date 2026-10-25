---
title: Concepts
order: 20
---

## Concepts

### Gateway

`Omnitrade\GatewayInterface` is one provider, configured - a payment service (Stripe, PayPal) or a
commerce platform (Shopify, WooCommerce). Every gateway answers the same methods; each typed
method is a shortcut for `execute()` with its request.

| Method | Returns | Request |
|---|---|---|
| `getName(): string` | the factory's name (`stripe`, `paypal`), also the `provider` of every model it returns | - |
| `getTitle(): string` | the provider's display name (`Stripe`, `PayPal`) | - |
| `supports(string $request): bool` | whether one of the gateway's actions answers that request class | - |
| `execute(Request $request): Request` | the same request, answered | any |
| `purchase(Payment $payment): Transaction` | the payment: PAID when taken at once, PENDING with a `redirectUrl` when the buyer pays on a page | `Purchase` |
| `authorize(Payment $payment): Transaction` | a reservation, captured or voided later | `Authorize` |
| `capture(string $reference, ?Money $amount = null, ?string $idempotencyKey = null): Transaction` | the authorization taken, all of it or `$amount` | `Capture` |
| `refund(string $reference, ?Money $amount = null, ?string $idempotencyKey = null, ?string $reason = null): Refund` | money given back, all of it or `$amount` | `Refund` |
| `void(string $reference): Transaction` | the authorization let go | `VoidAuthorization` |
| `fetch(string $reference): Transaction` | the transaction as the provider has it now | `FetchTransaction` |
| `paymentMethods(?Money $for = null, ?string $country = null): array` | `PaymentMethod[]` | `GetPaymentMethods` |
| `notify(string $body, array $headers = []): Notification` | a webhook, checked against its signature, read as one event | `Notify` |
| `fetchOrder(string $reference): PlatformOrder` | a platform's own order | `FetchOrder` |
| `fetchProduct(Reference\|string $reference): ?Product` | one product, by id or page address; `null` when there is none | `FetchProduct` |
| `fetchProducts(?string $cursor = null, ?\DateTimeInterface $updatedSince = null, ?string $query = null, int $limit = 50): ProductPage` | a page of the catalogue | `FetchProducts` |
| `fetchInventory(array $references = []): array` | `list<Stock>`, of these variants or of all | `FetchInventory` |
| `affiliateLink(Reference\|string $reference, ?string $tag = null): ?string` | the address with the site's affiliate tag; `null` when there is no programme for it | `AffiliateLink` |
| `createAccount(string $country, ?string $email = null, string $type = Account::EXPRESS, array $metadata = []): Account` | a connected account | `CreateAccount` |
| `accountLink(string $reference, string $returnUrl, string $refreshUrl): string` | the provider's onboarding page | `AccountLink` |
| `fetchAccount(string $reference): Account` | the account as it stands | `FetchAccount` |
| `subscribe(Payment $payment, string $interval = 'month', int $intervalCount = 1, ?string $price = null): Transaction` | a subscription started on the provider's page | `Subscribe` |
| `fetchSubscription(string $reference): Subscription` | the subscription as it stands | `FetchSubscription` |
| `cancelSubscription(string $reference, bool $atPeriodEnd = true): Subscription` | the subscription stopped, at the period's end by default | `CancelSubscription` |
| `subscriptionPortal(string $customer, string $returnUrl, ?string $locale = null): string` | the provider's page where a subscriber manages it | `SubscriptionPortal` |

Some request fields have no argument in the shortcut: `createAccount()` sends no
`businessType`, the default `capabilities` (`['transfers']`) and no idempotency key, `subscribe()`
no `trialDays` nor `customer`, `accountLink()` the `onboarding` type. Build the request and pass it
to `execute()` for those:

```php
use Omnitrade\Request\CreateAccount;
use Omnitrade\Request\Subscribe;

$account = $gateway->execute(new CreateAccount('FR', 'lea@example.org', capabilities: ['transfers', 'card_payments'], idempotencyKey: 'host-42'))->getAccount();
$transaction = $gateway->execute(new Subscribe($payment, 'month', trialDays: 14, customer: 'cus_Q1x'))->getTransaction();
```

`Omnitrade\Gateway` is the one implementation: a name, a title and a list of actions. `execute()`
hands the request to the first action whose `supports()` accepts it; when none does it throws
`RequestNotSupportedException` (`The "shopify" gateway does not support Authorize.`) before
anything is sent; when the action returns without setting a result it throws a
`ProviderException` (`... left the request unanswered.`). `supports()` probes the actions with an
instance of the request class built without its constructor, so it costs no call.

### Factory and Config

`Omnitrade\GatewayFactoryInterface` builds a provider's gateway from options:

| Method | |
|---|---|
| `getName(): string` | the name gateways are configured with: `stripe`, `paypal`, `shopify`, `woocommerce`, `web`, `amazon` |
| `create(array $options = []): GatewayInterface` | a gateway, its options validated |

Every provider extends `Omnitrade\GatewayFactory`, which takes an optional
`Symfony\Contracts\HttpClient\HttpClientInterface` in its constructor (none: the provider calls
`HttpClient::create()` itself) and does the work in `create()`:

1. `createConfig($options)` - a `Config` (an `ArrayObject`) holding the options given, then the
   provider's `populateConfig()` fills each key the options did not set with its default;
2. the keys listed in `omnitrade.required_options` are checked: one missing, `''` or `[]` is an
   `InvalidConfigException` listing every missing key;
3. `omnitrade.api` - the provider's API client, usually a closure of the `Config` - is built;
4. every key starting with `omnitrade.action.` that is (or whose closure returns) an
   `ActionInterface` becomes an action, in the order of the keys; a closure returning `null`
   registers no action, which is how a provider makes an operation depend on an option (Amazon's
   reads, which need credentials). Actions implementing `ApiAwareInterface` receive the API client;
5. a `Gateway` is returned, named by `omnitrade.factory_name` and titled by
   `omnitrade.factory_title`.

`getName()` builds a `Config` with no options and reads its `omnitrade.factory_name`.

The reserved keys a factory sets:

| Key | |
|---|---|
| `omnitrade.factory_name` | the gateway's name |
| `omnitrade.factory_title` | the gateway's title |
| `omnitrade.required_options` | list of option names that must be filled |
| `omnitrade.api` | the API client, or a `Closure(Config): object` that builds it |
| `omnitrade.action.<name>` | an `ActionInterface`, or a `Closure(Config): ?ActionInterface` |

Because the options are set before the defaults, a key such as `omnitrade.action.notify` given in
the options replaces the provider's own action. `Config::defaults($defaults)` sets only keys not
set yet, `Config::get($key, $default = null)` reads one, `Config::validateNotEmpty($keys)` is
step 2 (its message names the gateway: `The "paypal" gateway needs: secret.`).

### Registry

`Omnitrade\Registry` holds the shop's gateways by name:

```php
$registry = new Registry(
    [new StripeGatewayFactory($http), new PayPalGatewayFactory($http)],   // iterable of factories, keyed by getName()
    [
        'card' => ['factory' => 'stripe', 'options' => ['api_key' => '...']],
        'paypal' => ['factory' => 'paypal', 'options' => ['client_id' => '...', 'secret' => '...']],
    ],
);
```

| Method | |
|---|---|
| `get(string $name): GatewayInterface` | the gateway, built on the first call and kept |
| `has(string $name): bool` | whether a gateway of that name is configured |
| `options(string $name): array` | the options it is configured with (`[]` for an unknown name) |
| `create(string $name, array $overrides = []): GatewayInterface` | a gateway built afresh, `$overrides` over its configured options (`array_replace`), not kept |
| `names(): list<string>` | the configured gateways' names |
| `all(): array<string, GatewayInterface>` | every configured gateway, built |
| `factories(): list<string>` | the factories' names: what `factory:` may name |

An unknown name is an `InvalidConfigException` naming the configured ones
(`No "card" gateway; configured: paypal, shop.`), a factory not given to the registry one naming
the installed ones (`No "stripe" factory for the "other" gateway; installed: paypal, shopify.`).

### Requests and actions

A request (`Omnitrade\Request\Request`) is a question put to a gateway: `setResult($result)` answers
it, `getResult()` reads the answer, `isAnswered()` says whether an action set one (a `null` result
counts: `fetchProduct()` of an unknown product is answered `null`). Each request has a typed
getter for its result.

| Request | Constructor | Result, getter |
|---|---|---|
| `Purchase` | `Payment $payment` | `?Transaction`, `getTransaction()` |
| `Authorize` | `Payment $payment` | `?Transaction`, `getTransaction()` |
| `Capture` | `string $reference, ?Money $amount = null, ?string $idempotencyKey = null` | `?Transaction`, `getTransaction()` |
| `VoidAuthorization` | `string $reference` | `?Transaction` (CANCELLED), `getTransaction()` |
| `Refund` | `string $reference, ?Money $amount = null, ?string $idempotencyKey = null, ?string $reason = null` | `?Refund`, `getRefund()` |
| `FetchTransaction` | `string $reference` | `?Transaction`, `getTransaction()` |
| `GetPaymentMethods` | `?Money $for = null, ?string $country = null` | `PaymentMethod[]`, `getMethods()` (`[]` when unanswered) |
| `Notify` | `string $body, array $headers = []` | `?Notification`, `getNotification()`; `header($name)` reads a header whatever its case, the first value of a list |
| `FetchOrder` | `string $reference` | `?PlatformOrder`, `getOrder()` |
| `FetchProduct` | `Reference\|string $reference` (made a `Reference` with `Reference::of()`) | `?Product`, `getProduct()` |
| `FetchProducts` | `?string $cursor = null, ?\DateTimeInterface $updatedSince = null, ?string $query = null, int $limit = 50` | `ProductPage`, `getPage()` (an empty page when unanswered) |
| `FetchInventory` | `array $references = []` | `list<Stock>`, `getStocks()` |
| `AffiliateLink` | `Reference\|string $reference, ?string $tag = null` | `?string`, `getUrl()` |
| `CreateAccount` | `string $country, ?string $email = null, string $type = Account::EXPRESS, ?string $businessType = null, array $metadata = [], array $capabilities = ['transfers'], ?string $idempotencyKey = null` | `?Account`, `getAccount()` |
| `AccountLink` | `string $reference, string $returnUrl, string $refreshUrl, string $type = AccountLink::ONBOARDING` | `?string`, `getUrl()` |
| `FetchAccount` | `string $reference` | `?Account`, `getAccount()` |
| `Subscribe` | `Payment $payment, string $interval = 'month', int $intervalCount = 1, ?string $price = null, ?int $trialDays = null, ?string $customer = null` | `?Transaction`, `getTransaction()` |
| `FetchSubscription` | `string $reference` | `?Subscription`, `getSubscription()` |
| `CancelSubscription` | `string $reference, bool $atPeriodEnd = true` | `?Subscription`, `getSubscription()` |
| `SubscriptionPortal` | `string $customer, string $returnUrl, ?string $locale = null` | `?string`, `getUrl()` |

Constants: `AccountLink::ONBOARDING` (`onboarding`), `AccountLink::UPDATE` (`update`);
`Subscribe::INTERVALS` (`day`, `week`, `month`, `year`). `Subscribe` refuses another interval, or
an `$intervalCount` under 1, with an `\InvalidArgumentException`.

An action (`Omnitrade\Action\ActionInterface`) answers the requests it supports:
`supports(Request $request): bool` and `execute(Request $request): void`, which sets the result or
throws a `ProviderException`. An action that talks to the service implements
`ApiAwareInterface` (`setApi(object $api): void`); `ApiAwareTrait` implements it, checking that
the API is an instance of the class named in `$this->apiClass` (else an
`InvalidConfigException`, `... needs a <class>, got <class>.`). How to write one:
[Writing a provider](providers.md).

### Money

`Omnitrade\Model\Money` is an amount in a currency's **minor units**: `Money::of(1250, 'EUR')` is
12,50 EUR. Every amount of every model is a `Money`.

| Member | |
|---|---|
| `int $amount` | minor units (cents) |
| `string $currency` | ISO 4217, upper-cased by the constructor (`eur` becomes `EUR`) |
| `Money::of(int $amount, string $currency)` | the same as `new Money()` |
| `equals(Money $other): bool` | same amount, same currency |
| `decimal(): string` | in major units, as a decimal API expects (`12.50`) |
| `Money::fromDecimal(string\|float $decimal, string $currency)` | from a provider's decimal, rounded to the minor unit |
| `Money::minorDigits(string $currency): int` | 0 for BIF, CLP, DJF, GNF, ISK, JPY, KMF, KRW, MGA, PYG, RWF, UGX, UYI, VND, VUV, XAF, XOF, XPF; 3 for BHD, IQD, JOD, KWD, LYD, OMR, TND; 2 for any other |
| `__toString()` | `12.50 EUR` |

```php
echo Money::of(1250, 'eur'), ' | ', Money::of(1250, 'JPY'), ' | ', Money::of(1250, 'KWD');   // 12.50 EUR | 1250 JPY | 1.250 KWD
Money::fromDecimal('12.50', 'EUR')->amount;    // 1250
Money::fromDecimal('1250', 'JPY')->amount;     // 1250
Money::fromDecimal(0.1 + 0.2, 'EUR')->amount;  // 30
```

(run on 2026-10-25 in the harness image)

### Payment, Customer, Line

`Omnitrade\Model\Payment` is what to charge, and everything a provider may want with it. The amount
is the truth; the lines are for the buyer's eyes (a hosted page), and a provider that cannot take
them as given charges the amount as one line.

| Field | Type | Default | |
|---|---|---|---|
| `amount` | `Money` | required | what is charged |
| `reference` | `string` | required | yours - the order's -, sent to the provider as the merchant reference |
| `description` | `?string` | `null` | the single line's label when the lines are not listed |
| `customer` | `?Customer` | `null` | who pays |
| `lines` | `list<Line>` | `[]` | what is paid for |
| `shipping` | `?Money` | `null` | posting, on top of the lines; inside `amount` |
| `discount` | `?Money` | `null` | money off, taken from the lines; inside `amount` |
| `returnUrl` | `?string` | `null` | where the buyer comes back after a hosted page, paid or not |
| `cancelUrl` | `?string` | `null` | where the buyer goes when they give up |
| `idempotencyKey` | `?string` | `null` | the same purchase asked twice happens once, at providers that honour one: give it per attempt, and keep it with the attempt |
| `method` | `?string` | `null` | a provider's payment method to insist on (`card`, `sepa_debit`), else its choice |
| `notice` | `?string` | `null` | a mention to print on the page and the receipt (why there is no VAT) |
| `metadata` | `array<string, scalar>` | `[]` | kept by providers that keep metadata, given back on `notify()` |
| `locale` | `?string` | `null` | BCP 47, for the provider's page |
| `destination` | `?string` | `null` | a connected account the money goes to (`acct_...`): [connected accounts](connect.md) |
| `applicationFee` | `?Money` | `null` | what the platform keeps of a payment sent to `destination`; inside `amount` |

The constructor throws an `\InvalidArgumentException` for an `applicationFee` without a
`destination` (`An application fee is kept on a payment sent to a destination account.`) or one in
another currency, negative, or larger than the amount. `linesAddUp(): bool` says whether there are
lines and the lines' totals plus `shipping` minus `discount` equal the amount, all in its
currency: then a provider may list them. Which fields each provider reads:
[Configuration](configuration.md#what-each-provider-reads-of-a-payment).

`Omnitrade\Model\Customer` - all optional: `email`, `name`, `reference` (the provider's own id for
them, Stripe's `cus_...`), `phone`, `street` (`list<string>`), `postalCode`, `city`, `country` (ISO
3166-1 alpha-2).

`Omnitrade\Model\Line` - `label` (string), `unitAmount` (`Money`), `quantity` (int, 1), `sku`,
`imageUrl`, `reference` (the provider's own id for it: a Shopify variant gid, a WooCommerce product
id), `physical` (bool, `false`: goods to post, as opposed to a service or a download).
`total(): Money` is `unitAmount` times `quantity`.

### Transaction and Status

`Omnitrade\Model\Transaction` is a payment as the provider knows it.

| Field | Type | |
|---|---|---|
| `provider` | `string` | the gateway's name |
| `reference` | `string` | the provider's id: a Checkout session, a PayPal order, a Shopify draft order, a WooCommerce order |
| `status` | `Status` | where it stands |
| `amount` | `?Money` | what it is for |
| `redirectUrl` | `?string` | the page the buyer must be sent to (with PENDING) |
| `message` | `?string` | the provider's word on it: a refusal's reason, the provider's own status |
| `providerCode` | `?string` | the provider's code for it |
| `method` | `?string` | the payment method, as the provider names it (`card`, `paypal`) |
| `refunded` | `?Money` | what was given back so far |
| `metadata` | `array<string, mixed>` | the provider's metadata, or what the provider package keeps there (PayPal's capture id) |
| `createdAt` | `?\DateTimeImmutable` | |
| `raw` | `array` | the provider's whole answer |

`isRedirect()` is true when there is a `redirectUrl` and the status is PENDING; `isPaid()`,
`isPending()`, `isRefused()` test the status.

`Omnitrade\Model\Status`, a string-backed enum:

| Case | Value | `isFinal()` | `isSettled()` | |
|---|---|---|---|---|
| `PENDING` | `pending` | no | no | started, not settled: the buyer is on the provider's page, a transfer is on its way |
| `AUTHORIZED` | `authorized` | no | no | the money is reserved; `capture()` takes it |
| `PAID` | `paid` | yes | yes | |
| `REFUSED` | `refused` | yes | no | |
| `CANCELLED` | `cancelled` | yes | no | |
| `EXPIRED` | `expired` | yes | no | |
| `PARTIALLY_REFUNDED` | `partially_refunded` | yes | yes | |
| `REFUNDED` | `refunded` | yes | yes | |

`isFinal()` is "nothing more will happen to it", `isSettled()` "the money came in (and may since
have gone back)". `isFinal()` is true for PARTIALLY_REFUNDED although a further refund can still
follow: a status of the payment, not of its refunds.

### Refund

`Omnitrade\Model\Refund`: `provider`, `reference` (the provider's refund id, Stripe's `re_...`),
`amount` (`Money`), `status` (`Status`: REFUNDED once done, PENDING while on its way, REFUSED when
the provider failed it), `transactionReference` (the transaction refunded), `message`, `raw`.

### PaymentMethod

`Omnitrade\Model\PaymentMethod`: `code` (as the provider names it: `card`, `paypal`, `sepa_debit`,
`klarna`), `label`, `kind` (one of the constants), `currencies` (`list<string>`, empty for any),
`raw`. Kinds: `PaymentMethod::CARD` (`card`), `WALLET` (`wallet`), `BANK` (`bank`),
`BUY_NOW_PAY_LATER` (`bnpl`), `OTHER` (`other`, the default).

### Notification

`Omnitrade\Model\Notification` is what a provider is telling the shop, checked: one event about one
transaction, product, account or subscription.

| Field | Type | |
|---|---|---|
| `provider` | `string` | |
| `event` | `string` | the provider's event type: `checkout.session.completed`, `PAYMENT.CAPTURE.COMPLETED`, `orders/paid`, `order.updated` |
| `reference` | `?string` | what it concerns, by its id there: the transaction (or platform order), the product, the account, the subscription |
| `status` | `?Status` | what it means for the transaction, when it means anything (`null`: a hint to ignore, or to read from `raw`) |
| `transaction` | `?Transaction` | the transaction as the event carries it, when it does |
| `id` | `?string` | the provider's id for the event, to handle it once |
| `raw` | `array` | the event as received (Shopify and WooCommerce: the topic, headers read and the payload) |
| `product` | `?Product` | a catalogue event's product (`null` for a deletion) |
| `stocks` | `list<Stock>` | a stock event's levels |
| `account` | `?Account` | a connected account's event: the account as it stands |
| `subscription` | `?Subscription` | a subscription's event: the subscription as it stands |

`isAccount()` and `isSubscription()` test `account` and `subscription`; `isCatalogue()` is true
when there is a product or stocks, or when the event's name starts with `product` or `inventory`.

### PlatformOrder

`Omnitrade\Model\PlatformOrder` is a commerce platform's order (Shopify, WooCommerce): `provider`,
`reference` (the platform's id), `number` (the number people see, `#1042`), `status` (`Status`),
`total` (`Money`), `customer` (`?Customer`), `lines` (`list<Line>`), `financialStatus` and
`fulfillmentStatus` (the platform's own words), `createdAt`, `raw`.

### The catalogue

All `final readonly`, under `Omnitrade\Model` (a summary also in [catalogue](catalogue.md)):

`Product` - a product as a platform has it:

| Field | Type | |
|---|---|---|
| `provider` | `string` | |
| `reference` | `string` | the platform's id (a Shopify gid, a WooCommerce id, a Stripe `prod_...`, an ASIN, a page's canonical address) |
| `title` | `string` | |
| `description` | `?string` | HTML, as the platform holds it (plain text from Amazon's features and from a web page) |
| `handle` | `?string` | its handle or slug there |
| `brand` | `?string` | Shopify's vendor, WooCommerce's brand, a `brand` metadata |
| `url` | `?string` | the product page, when there is one |
| `status` | `string` | `Product::ACTIVE` (`active`), `DRAFT` (`draft`), `ARCHIVED` (`archived`) |
| `tags` | `list<string>` | |
| `categories` | `list<string>` | categories, product types, collections, by name |
| `attributes` | `array<string, string\|list<string>>` | key/value: metafields, metadata, WooCommerce attributes |
| `options` | `list<Option>` | what the variants differ by |
| `variants` | `list<ProductVariant>` | at least one |
| `media` | `list<Media>` | |
| `merchant` | `?Merchant` | who sells it, when it is not the shop itself |
| `updatedAt` | `?\DateTimeImmutable` | |
| `raw` | `array` | |

`isActive()`, `variant(string $reference): ?ProductVariant`, `price(): ?Money` (the lowest of the
variants' first offers).

`ProductVariant` - one thing one can put in a cart: `reference`, `title` (`null` for a product's
only variant), `sku`, `barcode`, `offers` (`list<Offer>`, the shop's own first), `options`
(`array<string, string>`, its value of each option by the option's name), `stock` (`?Stock`),
`image` (`?Media`), `attributes`, `weight` (grams), `raw`. `offer()` is the first offer, `price()`
its price.

`Offer` - a price at which a variant can be bought, and from whom: `price` (`Money`), `compareAt`
(the struck-through price), `available` (bool, `true`), `url` (where to buy it), `merchant` (`null`:
the shop itself), `reference` (the platform's id for the price: a Stripe price), `taxIncluded`
(`?bool`, as the platform says).

`Stock` - how many of a variant are left: `reference` (the variant, or the product when it has no
variants), `quantity` (`?int`), `tracked` (bool, `true`), `sku`, `location` (where it is held, when
the platform counts by location), `item` (the platform's id for the counted item: a Shopify
inventory item). A platform that does not track it says `tracked: false` and `quantity: null`.
`available()` is true when not tracked, when the quantity is unknown, or above 0.

`Media` - `url`, `alt`, `type` (`Media::IMAGE` `image` or `Media::VIDEO` `video`), `width`, `height`,
`reference`. `Option` - `name`, `values` (`list<string>`). `Merchant` - `name`, `reference`, `url`,
`country` (ISO 3166-1 alpha-2). `ProductPage` - `products` (`list<Product>`), `next` (the cursor of
the next page, `null` on the last); `hasMore()`.

`Reference` - which product is meant: `id` or `url`, at least one (else an
`\InvalidArgumentException`). `Reference::of($idOrUrl)` trims the string and makes it a `url` when
it starts with `http://` or `https://`, an `id` otherwise; `isUrl()`, `path()` (the URL's path
without the query), `slug()` (its last segment, URL-decoded), `__toString()` (the id, else the
URL).

```php
Reference::of('  https://cave.example/products/margaux-2019?variant=7 ');
// id null, url 'https://cave.example/products/margaux-2019?variant=7', path '/products/margaux-2019', slug 'margaux-2019'
Reference::of('gid://shopify/Product/42');   // id 'gid://shopify/Product/42', isUrl() false
```

### Connected accounts and subscriptions

`Account` - somebody else's account at the provider, opened through the platform: `provider`,
`reference` (`acct_...`), `type` (`Account::EXPRESS` `express`, `STANDARD` `standard`, `CUSTOM`
`custom`), `country`, `email`, `detailsSubmitted`, `chargesEnabled`, `payoutsEnabled`,
`requirements` (`list<string>`, what the provider still wants: `external_account`),
`defaultCurrency`, `metadata`, `raw`. `isReady()` is `chargesEnabled && payoutsEnabled`.
[Connected accounts](connect.md).

`Subscription` - `provider`, `reference` (`sub_...`), `status` (`Subscription::ACTIVE` `active`,
`TRIALING` `trialing`, `PAST_DUE` `past_due`, `UNPAID` `unpaid`, `CANCELLED` `cancelled`,
`INCOMPLETE` `incomplete`, `PAUSED` `paused`), `customer` (the provider's customer, what the portal
is opened for), `amount`, `interval` (`day`, `week`, `month`, `year`), `intervalCount`,
`currentPeriodStart`, `currentPeriodEnd`, `cancelAtPeriodEnd`, `cancelledAt`, `price` (the
provider's price or product), `metadata`, `raw`. `isActive()` (active or trialing),
`isCancelled()`. [Subscriptions](subscriptions.md).

### Invariants

- Money in minor units, currencies ISO 4217 upper-case, countries ISO 3166-1 alpha-2.
- Every model a gateway returns carries the gateway's name as `provider` and a non-empty
  `reference`.
- `ProductPage::$next` is `null` on the last page, never an empty string.
- `raw` keeps what the provider answered: anything the models leave out is there.

The conformance suite checks these on every provider ([testing](testing.md#the-conformance-suite)).

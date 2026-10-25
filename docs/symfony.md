---
title: Symfony
order: 15
---

# Symfony

Omnitrade runs without a framework ([installation](installation.md)); in a Symfony application its
bundle does the wiring. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel` (6.4, 7 or 8) - are not required by `omniscient/omnitrade`: the application
has them, and nothing of them is loaded outside Symfony.

## Registering the bundle

There is no Flex recipe: register `Omnitrade\Bridge\Symfony\OmnitradeBundle` by hand.

```php
// config/bundles.php
return [
    // ...
    Omnitrade\Bridge\Symfony\OmnitradeBundle::class => ['all' => true],
];
```

## Configuration

```yaml
# config/packages/omnitrade.yaml
omnitrade:
    gateways:                      # by name: a factory and its options
        card:   { factory: stripe, options: { api_key: '%env(STRIPE_API_KEY)%', webhook_secret: '%env(STRIPE_WEBHOOK_SECRET)%' } }
        paypal: { factory: paypal, options: { client_id: '%env(PAYPAL_CLIENT_ID)%', secret: '%env(PAYPAL_SECRET)%', sandbox: true } }
```

```sh
# .env.local, or bin/console secrets:set
STRIPE_API_KEY=...
STRIPE_WEBHOOK_SECRET=...
```

The whole tree, as the bundle's configuration dumps it (`YamlReferenceDumper` on the bundle's
extension, run in the harness image on 2026-10-25; `bin/console config:dump-reference omnitrade`
prints the same in an application):

```yaml
omnitrade:

    # The shop's providers, by name: a factory (stripe, paypal, shopify, woocommerce, web, amazon...) and its options.
    gateways:

        # Prototype
        name:
            factory:              ~ # Required
            options:              []
```

| Key | Type | Default | |
|---|---|---|---|
| `omnitrade.gateways` | map, keyed by the gateway's name | `{}` | the shop's providers |
| `omnitrade.gateways.<name>.factory` | string, required, not empty | - | a factory's name: `stripe`, `paypal`, `shopify`, `woocommerce`, `web`, `amazon`, or an application's own factory's `getName()` |
| `omnitrade.gateways.<name>.options` | any value (a map) | `[]` | handed as they are to the factory's `create()`: the provider's options ([Configuration](configuration.md)) |

The options are not validated when the container compiles - the tree accepts any value - but
when the gateway is built: a required option missing or empty is an `InvalidConfigException`
naming it, the first time the gateway is asked for.

## Services

Every `omnitrade/*` provider package installed has its factory registered as a service, on the
application's `http_client` service (`nullOnInvalid()`: with no HTTP client service, the factory
makes its own). The bundle knows the six providers of the [table](index.md#providers) and
registers those whose class exists. What the container holds, with `card` and `paypal`
configured:

| Service id | Class | Autowiring | |
|---|---|---|---|
| `Omnitrade\Registry` | `Registry` | `Registry $registry` | every configured gateway by name: `get()`, `has()`, `names()`, `options()`, `all()`, `factories()`, `create()` with other options. Public. |
| `omnitrade.gateway.<name>` | `GatewayInterface` | `GatewayInterface $<name>` | one gateway, built by `Registry::get('<name>')` the first time it is injected |
| `Omnitrade\Stripe\StripeGatewayFactory`, `Omnitrade\PayPal\PayPalGatewayFactory`, `Omnitrade\Shopify\ShopifyGatewayFactory`, `Omnitrade\WooCommerce\WooCommerceGatewayFactory`, `Omnitrade\Web\WebGatewayFactory`, `Omnitrade\Amazon\AmazonGatewayFactory` | the provider's factory | by its class | tagged `omnitrade.gateway_factory` |

```php
use Omnitrade\GatewayInterface;

final class CheckoutController
{
    public function __construct(private readonly GatewayInterface $card)   // the "card" gateway
    {
    }
}
```

The argument's name selects the gateway: `GatewayInterface $card` is `omnitrade.gateway.card`
(Symfony's named autowiring aliases, `registerAliasForArgument()`; the container holds the alias
`Omnitrade\GatewayInterface $card`). A gateway name that is not a valid PHP variable name
(`my-card`) is still reachable by its service id or by the registry.

Nothing is built when the container compiles: a gateway is built the first time it is asked
for, and an option left empty only shows then (`InvalidConfigException`). Keys typed in a back
office rather than set in the environment: `$registry->create('card', ['api_key' => $stored])`, a
gateway built afresh with those options over the configured ones and not kept by the registry.

## An application's own provider

A class of the application that implements `Omnitrade\GatewayFactoryInterface` (usually by
extending `Omnitrade\GatewayFactory`, [Writing a provider](providers.md)) is autoconfigured: tagged
`omnitrade.gateway_factory`, it joins the registry, and its `getName()` can be named as a
`factory`:

```yaml
omnitrade:
    gateways:
        bank:
            factory: bank_transfer     # App\Payment\BankTransferGatewayFactory::getName()
```

`Tests/Bridge/OmnitradeBundleTest.php` checks it: two gateways of an application's own factory,
built from the configuration and injected by their names.

## A webhook controller

```php
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\GatewayInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentWebhookController extends AbstractController
{
    #[Route('/payment/webhook', methods: ['POST'])]
    public function __invoke(Request $request, GatewayInterface $card): Response
    {
        try {
            $notification = $card->notify($request->getContent(), $request->headers->all());
        } catch (InvalidNotificationException) {
            return new Response(status: 400);
        }
        // $notification: which payment, what became of it ($reference, $status, $transaction)

        return new Response(status: 204);
    }
}
```

`$request->headers->all()` gives each header as a list of values, lower-cased; `notify()` reads
headers whatever their case and takes the first value of a list ([webhooks](usage-webhooks.md)).

The bundle registers no command, event, Twig function or route: the gateways are services, used
from the application's controllers, message handlers and commands.

# Symfony

Omnitrade runs without a framework ([installation](installation.md)); in a Symfony application its
bundle does the wiring. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel` - are not required by `omniscient/omnitrade`: the application has them, and
nothing of them is loaded outside Symfony.

Register `Omnitrade\Bridge\Symfony\OmnitradeBundle` (no Flex recipe):

```php
// config/bundles.php
return [
    // ...
    Omnitrade\Bridge\Symfony\OmnitradeBundle::class => ['all' => true],
];
```

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

Every `omnitrade/*` package installed registers its factory, on the application's `http_client`.
What is autowired:

| Service | |
|---|---|
| `GatewayInterface $card` | one gateway by the argument's name (the configured name) |
| `Registry` | every configured gateway by name (`get()`, `has()`, `names()`, `create()` with other options) |

```php
public function __construct(private readonly GatewayInterface $card)
{
}
```

Nothing is built when the container compiles: a gateway is built the first time it is asked
for, and an option left empty only shows then (`InvalidConfigException`). Keys typed in a back
office rather than set in the environment: `$registry->create('card', ['api_key' => $stored])`.

An application's own provider - a class implementing `GatewayFactoryInterface` - is registered
too, autoconfigured, and can be named as a `factory`.

```php
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
        // $notification: which payment, what became of it

        return new Response(status: 204);
    }
}
```

---
title: Laravel
order: 16
---

# Laravel

omnibridge/laravel, the bridge that hosts omnibase in Laravel, has no Omnitrade provider (it wires
omniauth, omnimap and omnishield among others, not Omnitrade). None is needed: the registry is
plain PHP, and a service provider of the application builds it once, from a config file, with a
`symfony/http-client` client (every provider requires that package; Laravel's own HTTP client is
Guzzle's, which the providers do not take).

The service provider below is written by hand in the application; it is not shipped by any
package.

```php
// config/omnitrade.php
return [
    'gateways' => [
        'card' => ['factory' => 'stripe', 'options' => [
            'api_key' => env('STRIPE_API_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ]],
        'paypal' => ['factory' => 'paypal', 'options' => [
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'secret' => env('PAYPAL_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'sandbox' => (bool) env('PAYPAL_SANDBOX', true),
        ]],
    ],
];
```

```php
// app/Providers/OmnitradeServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Omnitrade\GatewayInterface;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Registry;
use Omnitrade\Stripe\StripeGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

final class OmnitradeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class, function (): Registry {
            $http = HttpClient::create();

            return new Registry([
                new StripeGatewayFactory($http),
                new PayPalGatewayFactory($http),
            ], config('omnitrade.gateways'));
        });

        // One gateway as the default GatewayInterface, when the application has one provider.
        $this->app->bind(GatewayInterface::class, fn ($app) => $app->make(Registry::class)->get('card'));
    }
}
```

Register the provider (`bootstrap/providers.php` on Laravel 11 and later), then inject the
registry where it is needed:

```php
use Illuminate\Http\Request;
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Registry;

final class PaymentWebhookController
{
    public function __invoke(Request $request, Registry $registry)
    {
        try {
            $notification = $registry->get('card')->notify($request->getContent(), $request->headers->all());
        } catch (InvalidNotificationException) {
            return response('', 400);
        }
        // $notification->reference, ->status, ->transaction

        return response('', 204);
    }
}
```

A webhook route takes a POST from the provider without Laravel's CSRF token: exclude its path
from the CSRF middleware.

List in the provider every factory the application uses: unlike the Symfony bundle, a hand-built
registry knows only the factories it is given. Configuration caching (`php artisan config:cache`)
keeps working: the options are plain arrays read through `config()`. This page was written from
the registry's API; it was not run in a Laravel application.

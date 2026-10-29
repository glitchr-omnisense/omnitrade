---
title: The Docker harness
order: 70
---

# The Docker harness

`docker/` runs this package with every `omnitrade/*` provider installed - from GitHub (branch
1.x), or from the checkouts beside this one when `OMNITRADE_PLUGINS=../..` is set in `docker/.env`
- and a console that exercises them with the test keys in `docker/.env`.

```sh
cd docker && cp .env.dist .env       # your providers' test keys, when you have them
docker compose run --rm omnitrade gateways
```

| Command | Arguments and options | |
|---|---|---|
| `gateways` | - | the providers installed and configured from `.env`, and what each does (the payment and catalogue requests it supports) |
| `methods <gateway>` | `--amount`, `--currency` (`EUR`), `--country` | `paymentMethods()` |
| `purchase <gateway>` | `--amount` (minor units; default the lines' total, else 1990), `--currency` (`EUR`), `--reference` (`OMNITRADE-<date>`), `--method`, `--line "label=unit[xquantity][@reference]"` (repeatable) | a payment: its page to open, or its state |
| `authorize <gateway>` | as `purchase` | an authorization to capture later |
| `capture <gateway> <reference>` | `--amount`, `--currency` | an authorization captured, all or part |
| `void <gateway> <reference>` | - | an authorization voided |
| `fetch <gateway> <reference>` | - | where a transaction stands |
| `refund <gateway> <reference>` | `--amount`, `--currency`, `--reason` | a refund, whole or partial; its idempotency key is `refund-<date>` |
| `notify <gateway>` | `-H "Name: value"` (repeatable), the body on stdin | a webhook checked against its signature and read |
| `order <gateway> <reference>` | - | a platform's order |
| `catalogue <gateway>` | `--query`, `--since` (a date, or `-1 day`), `--limit` (10), `--cursor`, `--product <id or page>`, `--inventory` | products, variants, prices and stock as tables; the next page's `--cursor` printed under each page |
| `link <gateway> <reference>` | `--tag` | an affiliate link |
| `account <gateway> [<reference>]` | `--create <country>`, `--email`, `--link` | a connected account opened, read, or its onboarding page (back to `HARNESS_RETURN_URL`, refreshed at `HARNESS_CANCEL_URL`) |
| `subscribe <gateway>` | as `purchase`, plus `--interval` (`month`), `--price` | a subscription: its page to open |
| `subscription <gateway> <reference>` | `--cancel`, `--now` | a subscription read, or stopped (at the period's end, or at once with `--now`) |
| `bare` | `--recorded`, `--json` | plain PHP: the registry built by hand, a product page read, a payment asked, what PHP loaded |
| `test` | PHPUnit's arguments | every package's tests |
| `composer ...`, `sh`, `php ...` | | run as they are in the harness's directory |

Results are printed whole, as JSON (a transaction's page to open first, as `Open: <url>`); a
configuration error exits with code 2, a provider's error with code 1, each with its message.

```sh
docker compose run --rm omnitrade purchase stripe --amount 1990      # prints the Checkout page to open
docker compose run --rm omnitrade fetch stripe cs_test_...
docker compose run --rm omnitrade notify stripe -H 'Stripe-Signature: t=...,v1=...' < event.json
docker compose run --rm omnitrade catalogue shopify --since='-1 day' --limit=20
```

## Bare: no bundle, no container

The console above is a `symfony/console` application over a registry built by hand; `bare` is
less still - one PHP script, `docker/harness/bin/bare`, that requires the autoloader and nothing
else. It builds the `Registry` from the provider packages installed and asks each gateway that
can be built what it does. With `--recorded`, on the answers kept in `docker/harness/recorded/`
and without a call, it reads a product from its page's own markup through `omnitrade/web` and
asks PayPal for a payment of 12.50 EUR - an order, and the page to send the buyer to. Without
`--recorded` the gateways are those of `.env`, the page read is the one at `HARNESS_PRODUCT_URL`
when set, and nothing is bought. Then it lists what PHP loaded and exits 1 if a class of a
framework is among it (`Symfony\Component\DependencyInjection`, `Config`, `HttpKernel`,
`HttpFoundation`, a bundle, a bridge, Doctrine, Twig):

```
$ docker compose run --rm omnitrade bare --recorded
Omnitrade in bare PHP: the registry built by hand, no bundle, no container.

  web          product affiliate
  paypal       purchase authorize capture refund fetch methods notify

omnitrade/web, the page https://boutique.example/p/poussette-yoyo kept in recorded/:
  Poussette Yoyo³ & nacelle, Stokke, sold by Boutique SAS
  449.90 EUR, barcode 8716134000123, 2 pictures - read from its json-ld

omnitrade/paypal, 12.50 EUR for ORDER-1042, from the answers kept in recorded/:
  order 5O190127TN364715T, pending - the buyer goes to https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T

Loaded from Symfony: Symfony\Component\HttpClient, Symfony\Contracts\HttpClient, Symfony\Contracts\Service
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, a bundle, a bridge, Doctrine, Twig): none
```

(the same output on 2026-10-25, the script run in the harness image on the workspace's
checkouts, `OMNITRADE_AUTOLOAD` naming their autoloader, with no network)

The answers in `recorded/` were not taken from PayPal nor from a shop: PayPal's are written in
the shape its Orders v2 documentation gives, with that documentation's example ids and a token
that is manifestly not one; the page is omnitrade/web's own fixture, of a shop that does not
exist. `bare --recorded --json` prints the same whole - every class loaded, every file since the
autoloader - without a call: `Tests/BareTest.php` runs it in a process of its own and checks the
list.

`omnitrade/stripe` stands on Omnipay, which requires `symfony/http-foundation` for its own
request object: installed with it, loaded only when a Stripe payment is made - a library there,
with no kernel and no container.

The image is `php:8.4-cli-alpine` with Composer and `bcmath`; the harness's packages live in the
`harness` volume of the `omnitrade-harness` project.

## Configuration: docker/.env

`docker/.env` (git-ignored; copy `.env.dist`) holds the test keys. A gateway is configured once
every key it needs is set; the others are listed by `gateways` as "needs ...".

| Gateway | Needs | Other keys |
|---|---|---|
| `stripe` | `STRIPE_API_KEY` | `STRIPE_WEBHOOK_SECRET`, `STRIPE_ADAPTIVE_PRICING` (`1`: on) |
| `paypal` | `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET` | `PAYPAL_WEBHOOK_ID`, `PAYPAL_SANDBOX` (`0`: live; default sandbox) |
| `shopify` | `SHOPIFY_SHOP_DOMAIN`, `SHOPIFY_ADMIN_TOKEN` | `SHOPIFY_WEBHOOK_SECRET`, `SHOPIFY_API_VERSION`, `SHOPIFY_CURRENCY` |
| `woocommerce` | `WOOCOMMERCE_URL`, `WOOCOMMERCE_CONSUMER_KEY`, `WOOCOMMERCE_CONSUMER_SECRET` | `WOOCOMMERCE_WEBHOOK_SECRET`, `WOOCOMMERCE_CURRENCY`, `WOOCOMMERCE_WEIGHT_UNIT` |
| `web` | nothing | `WEB_AFFILIATE`: rules as `amazon.fr:tag=mysite-21,fnac.com:awc=1_2` |
| `amazon` | `AMAZON_PARTNER_TAG` | `AMAZON_CLIENT_ID`, `AMAZON_CLIENT_SECRET`, `AMAZON_MARKETPLACE` (`www.amazon.fr`), `AMAZON_CREDENTIAL_VERSION` (`3.2`) |

And for the commands: `HARNESS_RETURN_URL`, `HARNESS_CANCEL_URL` (where a hosted page sends the
buyer back), `HARNESS_CUSTOMER_EMAIL`, `HARNESS_CUSTOMER_NAME` (the payment's customer),
`HARNESS_PRODUCT_URL` (the page `bare` reads), `OMNITRADE_PLUGINS` (`../..`: the providers from the
checkouts beside the core).

## How it is put together

`docker/compose.yml` (project `omnitrade-harness`) has one service, `omnitrade`, image
`omnisense/omnitrade-harness`, which mounts the core at `/omnitrade/core`, the providers'
directory (`OMNITRADE_PLUGINS`, else the empty `docker/plugins/`) at `/omnitrade/plugins`, read
only, and the `harness` volume at `/harness`. At each start the entrypoint runs
`docker/harness/setup.php`, which writes `/harness/composer.json` and `/harness/phpunit.xml`: the
core as a path repository, each provider from its checkout under `/omnitrade/plugins/<slug>` when
there is one, else from `https://github.com/glitchr-omnisense/omnitrade-<slug>.git` (branch `1.x`),
plus `symfony/console`, `symfony/http-client`, `symfony/var-dumper` and, for the tests, PHPUnit and
the bundle's Symfony components. It runs `composer install` the first time and `composer update`
when that file changed, then the command. The first run needs the network; the volume keeps
the packages and Composer's cache between runs.

The `.env` read by the console comes from `env_file` (`required: false`): without it every
provider is listed as not configured, and `bare --recorded` and `test` still run.

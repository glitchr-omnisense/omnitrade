# Changelog

## [Unreleased]

## [1.1.0] - 2026-11-13

Saved payment methods, charged without a page. Additive only: no signature changed, nothing
removed; a provider that does not support the new requests refuses them as it refuses any other
(`RequestNotSupportedException`, `supports()` false).

### Added
- `Request\SetupMethod` (a payment method saved on the provider's own page, for its customer -
  made for the occasion when the buyer is none yet): result `Model\MethodSetup` (the page, the
  provider's customer).
- `Request\FetchSavedMethods` (the customer's methods as the provider has them now) and
  `Request\RemoveSavedMethod` (only a method of that customer): `Model\SavedMethod` (reference,
  customer, code, label, kind, brand, last four digits, expiry, `isExpired()`), never a number.
- `Payment::$savedMethod`, a last optional constructor argument: the saved method charged at once
  with no page (off-session); a bank that asks its buyer to confirm (3-D Secure) makes the
  transaction PENDING with its page as `redirectUrl`.
- `Gateway::OPERATIONS`: `setupMethod`, `fetchSavedMethods`, `removeSavedMethod` - requests
  without a shortcut on `GatewayInterface` (as `registerWebhook`), run through `execute()` and
  listed by `capabilities()`, so a site's usage policy can name them.
- Tests: `SavedMethodsTest` (3).

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Release
- Requires `omnisense/contracts` `^1.0`, a stable version, where it required `^1.0@dev`;
  `extra.branch-alias` `dev-1.x` → `1.x-dev`.
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml: the package installed alone,
  PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml` (that CI on the tag,
  then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the package's own tests.
- A factory's HTTP client always goes through the kernel's `Http\NoRetry`: the `class_exists()`
  fallback for an omnisense/contracts older than 1568f2c is gone (contracts 1.0 has it).
- `phpstan.neon` accepts the bundle's provider classes as absent (the package installed alone, as
  its own CI does) or known (the family's harness, every provider installed).
- README: the first call runs on PayPal's recorded answers (no account, nothing sent), and says what
  each provider needs for a real call; omnitrade/paypal is said to be on its own client, not Omnipay.

### Changed
- PHPStan at level max with an empty baseline: `Request\Request` is generic (`@template TResult`,
  each request `@extends Request<...>` with what its action answers, so a provider's `setResult()`
  is checked); the models' `$raw` are `array<mixed>`. Types only, in PHPDoc: no signature changed.
- On omnisense/contracts, the kernel of the omni families (required, `^1.0@dev`), the public API
  unchanged: `Config`, `Registry`, `GatewayFactory` and `Http\Redaction` are thin subclasses of the
  kernel's (prefix `omnitrade`, omnitrade's messages and exceptions); `GatewayInterface` extends
  its `ProviderInterface` (one method more, `capabilities()`: no class outside `Gateway` implements
  it), `GatewayFactoryInterface` its `ProviderFactoryInterface`; `OmnitradeException` extends its
  `ExceptionInterface`, `InvalidConfigException` its `InvalidConfigException` (an
  `\InvalidArgumentException`, so a `\LogicException` as before).
- The Symfony bundle is the only class needing a framework: `symfony/http-kernel`,
  `dependency-injection` and `config` stay suggested (require-dev), the bundle's file throws a
  `\LogicException` naming them when they are missing, and it is marked `@suggested` so the
  kernel's bare check leaves it out (`Tests/LoadAllTest.php`: every other class loads with
  omnisense/contracts and the HTTP client contracts alone).

### Added
- Writes sent once: the HTTP client a factory is given keeps its retries off
  (omnisense/contracts' `Http\NoRetry`), so Symfony's `RetryableHttpClient` (`framework.http_client`'s
  `retry_failed`) never sends a payment, a capture, a refund or an order twice. A `MockHttpClient`
  is kept as given. docs/policies.md.
- Usage policies: a provider declares its policy (`omnitrade.policy`: its `policy.json`), a gateway
  gives it (`DeclaresUsagePolicyInterface`) with its operations (`capabilities()`, `Capabilities`,
  `Gateway::OPERATIONS`); `Registry::policy()`, `policies()`, `guard()` and `guarded()` (the gateway
  behind its guard: a limit reached refuses a request before it is sent, webhooks never); a gateway's
  `policy` and `usage` beside its options; a shared limiter as the registry's third argument. The
  bundle takes `policy`, `usage` and `limiter`.
- `Http\Throttle`: a provider's API client honours the waits the provider asks for (the gateway's
  Backoff, `Config::backoff()`): no call while one lasts (`Exception\UsageLimitReachedException`), a
  429 a `Exception\RateLimitedException` (a `ProviderException`, `retryAfter`) - never retried.
- `Testing\BareTestCase`, the kernel's bare check for an omnitrade package.
- The harness installs omnisense/contracts from its checkout (`OMNISENSE_CONTRACTS`) or GitHub and runs
  its tests; `phpstan` runs PHPStan at level max on every package (`phpstan.neon`, a baseline that
  may only shrink).
- `docs/policies.md`; `errors.md`, `symfony.md`, `harness.md`, `README.md`.
- The `tags` option, every provider's: labels (`site: solstay`) put on everything a gateway
  creates at the provider, under the call's own metadata, which wins on a key both have but
  `site`, the configuration's (`Model\Tags`: `fromOption()`, `merge()`, `site()`). The factory
  hands them to the actions that create things (`Action\TagsAwareInterface`, `TagsAwareTrait`);
  a list or a nested value is an `InvalidConfigException`. `Notification::$tags` gives back the
  tags the event's object carries, `site()` and `isFromAnotherSite()` tell another site's event.
  `docs/several-sites.md`: a restricted key, a webhook endpoint and the tag per site, filtering,
  what each provider keeps and what it cannot.
- Registering the webhook from the application, an optional capability (no change to
  `GatewayInterface`): `Request\RegisterWebhook` (URL, events; registering an address again
  replaces it), answered by a `Model\Webhook` (provider id, URL, events, and the signing secret,
  given at creation only), `Request\FetchWebhooks`, `Request\DeleteWebhook`. `Http\PublicUrl::unreachable()`
  says why an address no provider can reach (localhost, `*.local`, `*.test`, private IP, HTTP)
  is refused. `docs/usage-webhooks.md` "Registering the webhook from the application".
- `Http\Redaction`: what the providers call at their catch of the HTTP client's exception, so
  that a `ProviderException` carries none of the request's secrets - its message with the key's
  parameters, the URL's user part and the request's secrets masked as `***` (`text()`), as
  previous a copy of the client's exception, the same class and code (`copy()`), and no trace
  of the chain keeping its arguments (`withoutArguments()`).

### Documentation
- `docs/usage-webhooks.md`: webhooks are optional - a scheduled read catches up everything they
  say, and a development machine needs neither a tunnel nor a webhook registered (never a
  `localhost` URL at the provider).

### Fixed
- `GatewayFactory::create()` throws what fails without arguments in its trace: with
  `zend.exception_ignore_args` off, an `InvalidConfigException` (a missing option) kept the
  options given, credentials included, in the arguments of `create()`.

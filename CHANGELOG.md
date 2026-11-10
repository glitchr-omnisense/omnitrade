# Changelog

## Unreleased (1.x)

### Documentation

- `docs/usage-webhooks.md`: webhooks are optional - a scheduled read catches up everything they
  say, and a development machine needs neither a tunnel nor a webhook registered (never a
  `localhost` URL at the provider).

### Added
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

### Fixed
- `GatewayFactory::create()` throws what fails without arguments in its trace: with
  `zend.exception_ignore_args` off, an `InvalidConfigException` (a missing option) kept the
  options given, credentials included, in the arguments of `create()`.

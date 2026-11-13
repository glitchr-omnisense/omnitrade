<?php

namespace Omnitrade;

use Omnisense\Contracts\Http\NoRetry;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Provider\Config as BaseConfig;
use Omnisense\Contracts\Provider\ProviderFactory;
use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\TagsAwareInterface;
use Omnitrade\Exception\UsageLimitReachedException;
use Omnitrade\Http\Redaction;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Omnibus way: a provider's factory fills a Config - its name, title,
 * the options it needs, its API client ("omnitrade.api", a closure of the
 * Config), its actions ("omnitrade.action.<name>") and its usage policy
 * ("omnitrade.policy": the path of its policy.json, or a UsagePolicy) - and
 * the gateway is those actions, the API handed to the ones that ask for it,
 * and the gateway's tags (the "tags" option, Model\Tags) to the ones that
 * label what they create.
 *
 * It is omnisense/contracts' ProviderFactory: before the API is built, the
 * Config holds the provider's Backoff (Config::backoff(), over the
 * registry's limiter), which the API observes on every answer
 * (Http\Throttle); guard() gives the gateway's PolicyGuard, its declared
 * policy tightened by the "policy" option and checked against "usage"
 * (Registry::guarded() puts the gateway behind it). The HTTP client given
 * is kept with its retries off (omnisense/contracts' Http\NoRetry): Symfony's
 * RetryableHttpClient never sends a payment, a capture or a refund twice.
 */
abstract class GatewayFactory extends ProviderFactory implements GatewayFactoryInterface
{
    protected readonly ?HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->http = null !== $http ? NoRetry::of($http) : null;
    }

    public function create(array $options = []): GatewayInterface
    {
        try {
            $gateway = parent::create($options);
        } catch (\Throwable $e) {
            // The options hold the credentials: no trace of the failure keeps them in its arguments.
            throw Redaction::withoutArguments($e);
        }

        return $gateway instanceof GatewayInterface ? $gateway : throw new \LogicException(static::class.' built no gateway.');
    }

    public function createConfig(array $options = []): Config
    {
        $config = parent::createConfig($options);

        return $config instanceof Config ? $config : throw new \LogicException('An omnitrade factory fills an Omnitrade\Config.');
    }

    abstract protected function populateConfig(Config $config): void;

    protected function newConfig(array $options): Config
    {
        return new Config($options);
    }

    protected function configure(BaseConfig $c): void
    {
        \assert($c instanceof Config);
        $this->populateConfig($c);
    }

    protected function make(BaseConfig $c): GatewayInterface
    {
        \assert($c instanceof Config);
        $tags = $c->tags();

        $api = $c->get('omnitrade.api');
        if ($api instanceof \Closure) {
            $api = $api($c);
        }

        $actions = [];
        foreach ($c as $key => $action) {
            if (!str_starts_with((string) $key, 'omnitrade.action.')) {
                continue;
            }
            if ($action instanceof \Closure) {
                $action = $action($c);
            }
            if (!$action instanceof ActionInterface) {
                continue;
            }
            if ($action instanceof ApiAwareInterface && \is_object($api)) {
                $action->setApi($api);
            }
            if ($action instanceof TagsAwareInterface) {
                $action->setTags($tags);
            }
            $actions[] = $action;
        }
        $name = $c->string('omnitrade.factory_name') ?? static::class;

        return new Gateway($name, $c->string('omnitrade.factory_title') ?? $name, $actions, self::policy($c->get('omnitrade.policy'), $name));
    }

    protected function limitException(): string
    {
        return UsageLimitReachedException::class;
    }

    /** The provider's declared policy: a UsagePolicy, the path of a policy.json, or unknown. */
    private static function policy(mixed $policy, string $name): UsagePolicy
    {
        return match (true) {
            $policy instanceof UsagePolicy => $policy,
            \is_string($policy) && '' !== $policy => UsagePolicy::fromFile($policy),
            default => UsagePolicy::unknown($name),
        };
    }
}

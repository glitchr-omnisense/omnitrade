<?php

namespace Omnitrade;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Http\Redaction;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Omnibus way: a provider's factory fills a Config - its name, title,
 * the options it needs, its API client ("omnitrade.api", a closure of the
 * Config) and its actions ("omnitrade.action.<name>") - and the gateway is
 * those actions, the API handed to the ones that ask for it.
 */
abstract class GatewayFactory implements GatewayFactoryInterface
{
    public function __construct(protected readonly ?HttpClientInterface $http = null)
    {
    }

    public function getName(): string
    {
        return $this->createConfig()['omnitrade.factory_name'];
    }

    public function create(array $options = []): GatewayInterface
    {
        try {
            $config = $this->createConfig($options);
            $config->validateNotEmpty($config->get('omnitrade.required_options', []));

            $api = $config->get('omnitrade.api');
            if ($api instanceof \Closure) {
                $api = $api($config);
            }

            $actions = [];
            foreach ($config as $key => $action) {
                if (!str_starts_with((string) $key, 'omnitrade.action.')) {
                    continue;
                }
                if ($action instanceof \Closure) {
                    $action = $action($config);
                }
                if (!$action instanceof ActionInterface) {
                    continue;
                }
                if ($action instanceof ApiAwareInterface && null !== $api) {
                    $action->setApi($api);
                }
                $actions[] = $action;
            }

            return new Gateway($config['omnitrade.factory_name'], $config['omnitrade.factory_title'], $actions);
        } catch (\Throwable $e) {
            // The options hold the credentials: no trace of the failure keeps them in its arguments.
            throw Redaction::withoutArguments($e);
        }
    }

    public function createConfig(array $options = []): Config
    {
        $config = new Config($options);
        $this->populateConfig($config);

        return $config;
    }

    abstract protected function populateConfig(Config $config): void;
}

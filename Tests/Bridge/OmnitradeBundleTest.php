<?php

namespace Omnitrade\Tests\Bridge;

use Omnitrade\Bridge\Symfony\OmnitradeBundle;
use Omnitrade\GatewayInterface;
use Omnitrade\Registry;
use Omnitrade\Tests\StubFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;

final class OmnitradeBundleTest extends TestCase
{
    public function testTheGatewaysConfiguredAreBuiltAndInjectableByName(): void
    {
        $container = new ContainerBuilder();
        $container->register('http_client', MockHttpClient::class);
        // An application's own provider, autoconfigured.
        $container->register(StubFactory::class)->setAutoconfigured(true);
        $container->register(Shop::class)->setAutowired(true)->setPublic(true);
        $bundle = new OmnitradeBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('omnitrade', ['gateways' => [
            'card' => ['factory' => 'stub', 'options' => ['token' => 'c']],
            'wallet' => ['factory' => 'stub', 'options' => ['token' => 'w']],
        ]]);
        $container->compile();

        $registry = $container->get(Registry::class);
        self::assertInstanceOf(Registry::class, $registry);
        self::assertSame(['card', 'wallet'], array_keys($registry->all()));

        $shop = $container->get(Shop::class);
        self::assertInstanceOf(Shop::class, $shop);
        self::assertSame('tx_c_1', $shop->card->purchase(\Omnitrade\Tests\Fixtures::payment())->reference, 'injected by its name');
        self::assertSame('stub', $shop->wallet->getName());
    }
}

final class Shop
{
    public function __construct(public readonly GatewayInterface $card, public readonly GatewayInterface $wallet)
    {
    }
}

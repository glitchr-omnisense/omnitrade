<?php

namespace Omnitrade\Tests;

use Omnitrade\Bridge\Symfony\OmnitradeBundle;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Registry;
use Omnitrade\Web\WebGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Omnitrade outside Symfony: the harness's bare script (docker/harness/bin/bare)
 * run in a PHP process of its own - this one has loaded the bundle's tests -
 * builds the registry by hand, reads a product from a page through
 * omnitrade/web and asks a payment of omnitrade/paypal, from the answers kept
 * in docker/harness/recorded/, and reports every class PHP loaded on the way
 * and every file since the autoloader. None may be a framework's.
 */
final class BareTest extends TestCase
{
    private const FRAMEWORK = '~^(?:Symfony\\\\Component\\\\(?:DependencyInjection|Config|HttpKernel|HttpFoundation)|Symfony\\\\Bundle|Symfony\\\\Bridge|Doctrine|Twig)\\\\~';
    private const FRAMEWORK_FILES = '~/vendor/(?:symfony/(?:dependency-injection|config|http-kernel|http-foundation|[a-z-]*bundle|[a-z-]*bridge)|doctrine|twig)/~';

    public function testTheRegistryIsBuiltByHandAndNoClassOfAFrameworkIsLoaded(): void
    {
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertContains(Registry::class, self::names($report, 'symbols'), 'the registry was built there');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
        $plugins = require __DIR__.'/../docker/harness/plugins.php';
        self::assertIsArray($plugins);
        $installed = array_values(array_filter(self::names(array_column($plugins, 1)), static fn (string $factory): bool => class_exists($factory)));
        foreach ($installed as $factory) {
            self::assertContains($factory, self::names($report, 'symbols'), 'every provider package installed, its factory built');
        }
        self::assertIsArray($report['factories'] ?? null);
        self::assertCount(\count($installed), $report['factories']);
    }

    public function testAProductIsReadFromARecordedPageWithNoClassOfAFrameworkLoaded(): void
    {
        if (!class_exists(WebGatewayFactory::class)) {
            self::markTestSkipped('omnitrade/web is not installed.');
        }
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertSame(['product', 'affiliate'], self::at($report, 'gateways', 'web'));
        self::assertSame([
            'page' => 'https://boutique.example/p/poussette-yoyo',
            'reference' => 'https://boutique.example/p/poussette-yoyo',
            'title' => 'Poussette Yoyo³ & nacelle',
            'brand' => 'Stokke',
            'merchant' => 'Boutique SAS',
            'price' => '449.90',
            'currency' => 'EUR',
            'barcode' => '8716134000123',
            'media' => 2,
            'read_from' => 'json-ld',
        ], $report['product'] ?? null);
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
    }

    public function testAPaymentIsAskedOfRecordedAnswersWithNoClassOfAFrameworkLoaded(): void
    {
        if (!class_exists(PayPalGatewayFactory::class)) {
            self::markTestSkipped('omnitrade/paypal is not installed.');
        }
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertTrue($report['recorded'] ?? null);
        self::assertSame(['purchase', 'authorize', 'capture', 'refund', 'fetch', 'methods', 'notify'], self::at($report, 'gateways', 'paypal'));
        self::assertSame(['provider' => 'paypal', 'reference' => '5O190127TN364715T', 'status' => 'pending', 'redirect' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T', 'is_redirect' => true], $report['payment'] ?? null);
        self::assertContains('Symfony\\Component\\HttpClient\\MockHttpClient', self::names($report, 'symbols'), 'the answers came through the HTTP client given');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
    }

    /** The check is not blind: the same report, once the bundle is loaded, names the framework. */
    public function testTheBundleDoesLoadTheFramework(): void
    {
        if (!class_exists(AbstractBundle::class)) {
            self::markTestSkipped('symfony/http-kernel is not installed.');
        }
        [$status, $report] = self::php(['-r', 'require getenv("OMNITRADE_AUTOLOAD"); $autoloaded = get_included_files(); class_exists($argv[1]) || exit(2); echo json_encode(["symbols" => [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()], "files" => array_values(array_diff(get_included_files(), $autoloaded))]);', '--', OmnitradeBundle::class]);

        self::assertSame(0, $status);
        $framework = self::framework($report);
        self::assertContains(AbstractBundle::class, $framework);
        self::assertNotEmpty(preg_grep('~/symfony/http-kernel/~', $framework));
    }

    /**
     * @param array<mixed> $report
     *
     * @return list<string> the classes, interfaces, traits and files of a framework among those loaded
     */
    private static function framework(array $report): array
    {
        $framework = [];
        foreach (['symbols' => self::FRAMEWORK, 'files' => self::FRAMEWORK_FILES] as $key => $pattern) {
            foreach (self::names($report, $key) as $name) {
                if (preg_match($pattern, $name)) {
                    $framework[] = $name;
                }
            }
        }

        return $framework;
    }

    /**
     * The report's value at this path of keys, each step an array.
     *
     * @param array<mixed> $report
     */
    private static function at(array $report, string ...$keys): mixed
    {
        $value = $report;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * A list of names: the array given, or the report's at this path; every item a string.
     *
     * @param array<mixed> $value
     *
     * @return list<string>
     */
    private static function names(array $value, string ...$keys): array
    {
        $value = self::at($value, ...$keys);
        self::assertIsArray($value);
        $names = array_values(array_filter($value, '\\is_string'));
        self::assertCount(\count($value), $names, 'only names');

        return $names;
    }

    /**
     * Runs PHP apart, on the autoloader of this run.
     *
     * @param list<string> $arguments
     *
     * @return array{int, array<mixed>} the exit status, the JSON printed
     */
    private static function php(array $arguments): array
    {
        $autoload = \dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php';
        $process = proc_open([\PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['OMNITRADE_AUTOLOAD' => $autoload] + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $status = proc_close($process);
        $report = json_decode($out, true);
        self::assertIsArray($report, 'PHP exited '.$status.': '.$err.$out);

        return [$status, $report];
    }
}

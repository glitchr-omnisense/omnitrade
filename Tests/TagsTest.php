<?php

namespace Omnitrade\Tests;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\TagsAwareInterface;
use Omnitrade\Action\TagsAwareTrait;
use Omnitrade\Config;
use Omnitrade\Exception\InvalidConfigException;
use Omnitrade\GatewayFactory;
use Omnitrade\Model\Money;
use Omnitrade\Model\Notification;
use Omnitrade\Model\Status;
use Omnitrade\Model\Tags;
use Omnitrade\Model\Transaction;
use Omnitrade\Registry;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;
use PHPUnit\Framework\TestCase;

/**
 * The "tags" option: labels set once in a gateway's configuration, merged under
 * the metadata of every create call (the caller's own winning, "site" the
 * configuration's), handed by the factory to the actions that create things,
 * and read back on a webhook's event.
 */
final class TagsTest extends TestCase
{
    public function testTheCallersMetadataWinsButNotOnTheSite(): void
    {
        $tags = Tags::fromOption(['site' => 'solstay', 'env' => 'demo', 'shared' => 'config']);

        self::assertSame('solstay', $tags->site());
        self::assertSame(
            ['site' => 'solstay', 'env' => 'demo', 'shared' => 'caller', 'order' => 'B-42', 'paid' => 'true'],
            $tags->merge(['shared' => 'caller', 'order' => 'B-42', 'site' => 'wineseller', 'paid' => true, 'none' => null]),
        );
        self::assertSame(['site' => 'solstay', 'env' => 'demo', 'shared' => 'config'], $tags->merge());
    }

    public function testNoTagsLeaveTheMetadataAsGiven(): void
    {
        $tags = Tags::fromOption(null);

        self::assertTrue($tags->isEmpty());
        self::assertNull($tags->site());
        self::assertSame([], $tags->merge());
        self::assertSame(['order' => 'B-42', 'site' => 'mine', 'n' => '3'], $tags->merge(['order' => 'B-42', 'site' => 'mine', 'n' => 3]));
    }

    /** An environment variable gives a string, a YAML number stays a number: both are text at the provider. */
    public function testScalarsAreText(): void
    {
        self::assertSame(['site' => 'solstay', 'year' => '2026', 'live' => 'false'], Tags::fromOption(['site' => 'solstay', 'year' => 2026, 'live' => false])->values);
    }

    /** @return iterable<string, array{mixed}> */
    public static function wrongOptions(): iterable
    {
        yield 'a list' => [['solstay']];
        yield 'a string' => ['solstay'];
        yield 'a nested map' => [['site' => ['name' => 'solstay']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongOptions')]
    public function testAWrongOptionIsAConfigurationError(mixed $option): void
    {
        $this->expectException(InvalidConfigException::class);
        Tags::fromOption($option);
    }

    public function testTheFactoryHandsTheTagsToTheActionsThatCreate(): void
    {
        $registry = new Registry([new TaggingFactory()], ['demo' => ['factory' => 'tagging', 'options' => ['tags' => ['site' => 'solstay']]]]);

        $transaction = $registry->get('demo')->purchase(Fixtures::payment());

        self::assertSame(['site' => 'solstay'], $transaction->metadata, 'the payment had none: the tags alone');
    }

    public function testAWrongTagsOptionFailsWhenTheGatewayIsBuilt(): void
    {
        $this->expectException(InvalidConfigException::class);
        (new TaggingFactory())->create(['tags' => ['solstay']]);
    }

    public function testWithoutTheOptionTheActionsGetNone(): void
    {
        $transaction = (new TaggingFactory())->create()->purchase(Fixtures::payment());

        self::assertSame([], $transaction->metadata);
    }

    public function testAnEventTellsWhichSiteItIsFor(): void
    {
        $ours = new Notification('stripe', 'checkout.session.completed', tags: ['site' => 'solstay', 'order' => 'B-42']);
        $theirs = new Notification('stripe', 'checkout.session.completed', tags: ['site' => 'wineseller']);
        $untagged = new Notification('stripe', 'checkout.session.completed');

        self::assertSame('solstay', $ours->site());
        self::assertFalse($ours->isFromAnotherSite('solstay'));
        self::assertTrue($theirs->isFromAnotherSite('solstay'));
        self::assertNull($untagged->site());
        self::assertFalse($untagged->isFromAnotherSite('solstay'), 'an object made in the dashboard, or by a provider keeping no tag, is not filtered out');
        self::assertFalse($theirs->isFromAnotherSite(null), 'a site with no tag of its own filters nothing');
    }
}

/** A provider whose purchase answers with the metadata it would have sent. */
final class TaggingFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnitrade.factory_name' => 'tagging',
            'omnitrade.factory_title' => 'Tagging',
            'omnitrade.action.purchase' => new TaggingPurchaseAction(),
        ]);
    }
}

final class TaggingPurchaseAction implements ActionInterface, TagsAwareInterface
{
    use TagsAwareTrait;

    public function supports(Request $request): bool
    {
        return $request instanceof Purchase;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Purchase);
        $request->setResult(new Transaction('tagging', 'tx_1', Status::PAID, Money::of(1250, 'EUR'), metadata: $this->tags()->merge($request->payment->metadata)));
    }
}

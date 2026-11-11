<?php

namespace Omnitrade\Tests;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Exception\RequestNotSupportedException;
use Omnitrade\Gateway;
use Omnitrade\Model\Media;
use Omnitrade\Model\Money;
use Omnitrade\Model\Offer;
use Omnitrade\Model\Option;
use Omnitrade\Model\Product;
use Omnitrade\Model\ProductPage;
use Omnitrade\Model\ProductVariant;
use Omnitrade\Model\Reference;
use Omnitrade\Model\Stock;
use Omnitrade\Request\AffiliateLink;
use Omnitrade\Request\FetchInventory;
use Omnitrade\Request\FetchProduct;
use Omnitrade\Request\FetchProducts;
use Omnitrade\Request\Request;
use PHPUnit\Framework\TestCase;

final class CatalogueTest extends TestCase
{
    public function testAReferenceIsAnIdOrAnAddress(): void
    {
        $id = Reference::of('gid://shopify/Product/42');
        self::assertFalse($id->isUrl());
        self::assertSame('gid://shopify/Product/42', $id->id);

        $url = Reference::of('https://cave.example/products/margaux-2019?variant=3');
        self::assertTrue($url->isUrl());
        self::assertSame('/products/margaux-2019', $url->path());
        self::assertSame('margaux-2019', $url->slug());
        self::assertSame($url, Reference::of($url));

        $this->expectException(\InvalidArgumentException::class);
        new Reference();
    }

    public function testTheGatewayAnswersTheCatalogueQuestions(): void
    {
        $gateway = new Gateway('cellar', 'Cellar', [new CellarAction()]);

        self::assertTrue($gateway->supports(FetchProducts::class));
        self::assertTrue($gateway->supports(FetchProduct::class));
        self::assertTrue($gateway->supports(FetchInventory::class));
        self::assertFalse($gateway->supports(AffiliateLink::class));

        $first = $gateway->fetchProducts(limit: 1);
        self::assertCount(1, $first->products);
        self::assertTrue($first->hasMore());
        $second = $gateway->fetchProducts($first->next, limit: 1);
        self::assertFalse($second->hasMore());
        self::assertSame('Chablis', $second->products[0]->title);

        $searched = $gateway->fetchProducts(query: 'margaux');
        self::assertCount(1, $searched->products);

        $byUrl = $gateway->fetchProduct('https://cave.example/products/margaux');
        self::assertNotNull($byUrl);
        self::assertSame('p1', $byUrl->reference);
        self::assertSame(2400, $byUrl->price()?->amount);
        self::assertSame('2018', $byUrl->variant('v2')?->options['Millésime']);
        self::assertNull($gateway->fetchProduct('nothing'));

        $stocks = $gateway->fetchInventory(['v1']);
        self::assertCount(1, $stocks);
        self::assertFalse($stocks[0]->available());

        $this->expectException(RequestNotSupportedException::class);
        $gateway->execute(new AffiliateLink('p1', 'amaury-21'));
    }
}

final class CellarAction implements ActionInterface
{
    /** @return list<Product> */
    private function products(): array
    {
        $eur = static fn (int $cents) => new Offer(Money::of($cents, 'EUR'));

        return [
            new Product('cellar', 'p1', 'Margaux', handle: 'margaux', brand: 'Château Exemple', options: [new Option('Millésime', ['2019', '2018'])],
                variants: [
                    new ProductVariant('v1', '2019', 'MGX19', offers: [$eur(2900)], options: ['Millésime' => '2019'], stock: new Stock('v1', 0)),
                    new ProductVariant('v2', '2018', 'MGX18', offers: [$eur(2400)], options: ['Millésime' => '2018'], stock: new Stock('v2', 12)),
                ],
                media: [new Media('https://cave.example/margaux.jpg', 'Margaux')]),
            new Product('cellar', 'p2', 'Chablis', handle: 'chablis', variants: [new ProductVariant('v3', offers: [$eur(1800)], stock: new Stock('v3', null, false))]),
        ];
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchProducts || $request instanceof FetchProduct || $request instanceof FetchInventory;
    }

    public function execute(Request $request): void
    {
        $all = $this->products();
        if ($request instanceof FetchProducts) {
            if (null !== $request->query) {
                $all = array_values(array_filter($all, static fn (Product $p) => false !== stripos($p->title, $request->query)));
            }
            $offset = (int) $request->cursor;
            $page = \array_slice($all, $offset, $request->limit);
            $next = $offset + $request->limit < \count($all) ? (string) ($offset + $request->limit) : null;
            $request->setResult(new ProductPage($page, $next));
        } elseif ($request instanceof FetchProduct) {
            $found = null;
            foreach ($all as $product) {
                if ($product->reference === $request->reference->id || $product->handle === $request->reference->slug()) {
                    $found = $product;
                }
            }
            $request->setResult($found);
        } elseif ($request instanceof FetchInventory) {
            $stocks = [];
            foreach ($all as $product) {
                foreach ($product->variants as $variant) {
                    if (null !== $variant->stock && ([] === $request->references || \in_array($variant->reference, $request->references, true))) {
                        $stocks[] = $variant->stock;
                    }
                }
            }
            $request->setResult($stocks);
        }
    }
}

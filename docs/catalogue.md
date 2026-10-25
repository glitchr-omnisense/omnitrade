---
title: Catalogue
order: 33
---

# The catalogue: products, variants, offers, stock

The same gateways that take payments read a platform's catalogue: a shop kept
in Shopify, WooCommerce or Stripe's own Products is read through one contract,
and a site copies it into its own tables (omnibase/marketplace's
`marketplace:catalogue:sync`) or shows it as it is.

```php
$gateway = $registry->get('shopify');

$page = $gateway->fetchProducts();                                   // the first page
while ($page->hasMore()) {
    $page = $gateway->fetchProducts($page->next);                    // the next one, by its cursor
}
$gateway->fetchProducts(updatedSince: new \DateTimeImmutable('-1 day'));   // what changed
$gateway->fetchProducts(query: 'margaux');                           // the platform's own search

$product = $gateway->fetchProduct('gid://shopify/Product/42');       // by its id there
$product = $gateway->fetchProduct('https://cave.example/products/margaux-2019');   // or by its page

$stocks = $gateway->fetchInventory(['gid://shopify/ProductVariant/7']);
```

## A complete example

A catalogue copied page by page, then two affiliate links. The script runs as it is (`composer
require omnitrade/woocommerce omnitrade/web omnitrade/amazon`): it answers in the shop's place,
with answers shaped as WooCommerce's REST API v3 gives them - two pages of one product each, the
second a variable product with two variations.

```php
<?php // catalogue.php: a WooCommerce catalogue copied page by page, on answers shaped as REST v3 gives them

require __DIR__.'/vendor/autoload.php';

use Omnitrade\Amazon\AmazonGatewayFactory;
use Omnitrade\Web\WebGatewayFactory;
use Omnitrade\WooCommerce\WooCommerceGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$products = [
    1 => [['id' => 501, 'name' => 'Riesling Grand Cru', 'slug' => 'riesling-grand-cru', 'type' => 'simple', 'status' => 'publish', 'permalink' => 'https://shop.example/product/riesling-grand-cru/',
        'regular_price' => '24.00', 'sale_price' => '19.50', 'on_sale' => true, 'sku' => 'RIE-GC', 'manage_stock' => true, 'stock_quantity' => 36, 'stock_status' => 'instock', 'weight' => '1.3',
        'categories' => [['name' => 'Blancs']], 'tags' => [], 'attributes' => [['name' => 'Brand', 'slug' => 'pa_brand', 'variation' => false, 'options' => ['Domaine Weinbach']]], 'images' => [['id' => 9, 'src' => 'https://shop.example/riesling.jpg', 'alt' => 'Riesling']], 'date_modified_gmt' => '2026-10-20T08:00:00']],
    2 => [['id' => 602, 'name' => 'Pinot Noir', 'slug' => 'pinot-noir', 'type' => 'variable', 'status' => 'publish', 'permalink' => 'https://shop.example/product/pinot-noir/',
        'categories' => [['name' => 'Rouges']], 'tags' => [['name' => 'bio']], 'attributes' => [['name' => 'Millésime', 'variation' => true, 'options' => ['2019', '2020']]], 'images' => [], 'date_modified_gmt' => '2026-10-21T08:00:00']],
];
$variations = [
    ['id' => 603, 'status' => 'publish', 'regular_price' => '18.00', 'sku' => 'PN-19', 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock', 'attributes' => [['name' => 'Millésime', 'option' => '2019']]],
    ['id' => 604, 'status' => 'publish', 'regular_price' => '16.00', 'sku' => 'PN-20', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'attributes' => [['name' => 'Millésime', 'option' => '2020']]],
];
$http = new MockHttpClient(static function (string $method, string $url, array $options) use ($products, $variations): MockResponse {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    [$body, $pages] = match (parse_url($url, PHP_URL_PATH)) {
        '/wp-json/wc/v3/products' => [$products[(int) ($query['page'] ?? 1)] ?? [], '2'],   // two pages of one product
        '/wp-json/wc/v3/products/602/variations' => [$variations, '1'],
    };

    return new MockResponse(json_encode($body), ['response_headers' => ['content-type' => 'application/json', 'x-wp-totalpages' => $pages]]);
});

$woo = (new WooCommerceGatewayFactory($http))->create(['url' => 'https://shop.example', 'consumer_key' => 'ck_not_a_real_key', 'consumer_secret' => 'cs_not_a_real_secret', 'currency' => 'EUR']);

$cursor = null;
do {
    $page = $woo->fetchProducts($cursor, updatedSince: new DateTimeImmutable('2026-10-01'), limit: 1);
    foreach ($page->products as $product) {
        printf("%s %s [%s] brand=%s categories=%s\n", $product->reference, $product->title, $product->status, $product->brand ?? '-', implode(',', $product->categories));
        foreach ($product->variants as $variant) {
            $offer = $variant->offer();
            printf("  %-4s %-6s %-8s %s%s stock=%s %s\n", $variant->reference, $variant->sku, $variant->title ?? '-', $offer?->price, $offer?->compareAt ? ' (was '.$offer->compareAt.')' : '', $variant->stock?->quantity ?? 'not counted', $offer?->available ? 'available' : 'unavailable');
        }
        echo '  lowest price: ', $product->price(), "\n";
    }
    $cursor = $page->next;
} while ($page->hasMore());

// Affiliate links: no call is made for either.
$web = (new WebGatewayFactory($http))->create(['affiliate' => ['fnac.com' => ['awc' => '1234_5678']]]);
var_dump($web->affiliateLink('https://www.fnac.com/a1234/livre?utm_source=news&ref=x'));
var_dump($web->affiliateLink('https://www.example.org/p/1'));
$amazon = (new AmazonGatewayFactory($http))->create(['partner_tag' => 'mysite-21']);
var_dump($amazon->affiliateLink('https://www.amazon.de/Some-Book/dp/B0C1234567/ref=sr_1_3?psc=1'));
```

```
$ php catalogue.php
501 Riesling Grand Cru [active] brand=Domaine Weinbach categories=Blancs
  501  RIE-GC -        19.50 EUR (was 24.00 EUR) stock=36 available
  lowest price: 19.50 EUR
602 Pinot Noir [active] brand=- categories=Rouges
  603  PN-19  2019     18.00 EUR stock=0 unavailable
  604  PN-20  2020     16.00 EUR stock=not counted available
  lowest price: 16.00 EUR
string(46) "https://www.fnac.com/a1234/livre?awc=1234_5678"
NULL
string(49) "https://www.amazon.de/dp/B0C1234567?tag=mysite-21"
```

(run on 2026-10-25 in the harness image, PHP 8.4)

What it shows: a sale price is the offer's `price` and the regular one its `compareAt`; a "Brand"
attribute is the brand; a variable product's variations are its variants, titled by their values,
the one out of stock not `available`; a variation that does not manage its stock has a `Stock` with
a `null` quantity (`not counted`); `Product::price()` is the lowest variant's. The cursor of a
WooCommerce page is its number (`"2"`); the loop ends when `hasMore()` is false. Neither
affiliate link makes a call: `omnitrade/web` adds the parameters configured for the host and drops
`utm_source` and `ref`, and gives `null` for a host it has no rule for; `omnitrade/amazon` keeps the
Amazon store of the address (`www.amazon.de`) and writes `/dp/<ASIN>?tag=<partner tag>`.

## Models

All `final readonly`, under `Omnitrade\Model`:

| Model | What it holds |
|---|---|
| `Product` | provider, reference (the platform's id), title, description (HTML), handle, brand (Shopify's vendor, WooCommerce's brand), url, status (`active`, `draft`, `archived`), tags, categories, attributes (key/value), options, variants, media, merchant, updatedAt, raw |
| `ProductVariant` | reference, title (null for an only variant), sku, barcode, offers, options (its value of each option), stock, image, attributes, weight (grams), raw |
| `Offer` | price (`Money`), compareAt, available, url, merchant (null: the shop itself), reference (a Stripe price), taxIncluded |
| `Stock` | reference (the variant), quantity (null: not counted), tracked, sku, location, item (a Shopify inventory item) |
| `Media` | url, alt, type (`image`, `video`), width, height, reference |
| `Option` | name, values: what the variants differ by ("Millésime: 2018, 2019") |
| `Merchant` | name, reference, url, country: who sells, when it is not the shop |
| `Reference` | id or url: `Reference::of()` tells them apart; `slug()` is the page's last segment |
| `ProductPage` | products, next (the cursor of the next page, null on the last) |

`ProductVariant::price()` is its first offer's price (the shop's own);
`Product::price()` the lowest of its variants'. What the platform answered is
kept in `$raw`.

## Requests

| Request | Result | Shortcut |
|---|---|---|
| `FetchProducts(?cursor, ?updatedSince, ?query, limit = 50)` | `ProductPage` | `fetchProducts()` |
| `FetchProduct(Reference\|string)` | `?Product` | `fetchProduct()` |
| `FetchInventory(list<string> $references = [])` | `list<Stock>` | `fetchInventory()` |
| `AffiliateLink(Reference\|string, ?tag)` | `?string` (a URL; null: no programme for it) | `affiliateLink()` |

A provider says what it reads with `supports()`: Stripe has no stock, so
`supports(FetchInventory::class)` is false there and the stock stays the
site's.

| Provider | FetchProducts | FetchProduct (id / URL) | FetchInventory |
|---|---|---|---|
| `omnitrade/shopify` | Admin GraphQL `products` (`endCursor`), `updated_at:>` and search filters | gid, numeric id, handle / handle from `/products/<handle>`, id from `/admin/products/<id>` | `nodes(ids:)` on the variants, or every `productVariants` |
| `omnitrade/woocommerce` | REST v3 `/products` (page number, `modified_after`, `search`), variations | id (a variation's gives its product), slug / slug, `?p=<id>` | `stock_quantity` of products and variations |
| `omnitrade/web` | not supported | none / any product page: JSON-LD `Product` or `ProductGroup`, microdata, OpenGraph | not supported |
| `omnitrade/amazon` (with `client_id` and `client_secret`) | Creators API `searchItems` (a query is required, else a `ProviderException` `query_required`; 1 to 10 items; no paging) | ASIN / an Amazon page naming one (`getItems`) | not supported |
| `omnitrade/stripe` | Products (`starting_after`; `search` on `name~` for a query; `updatedSince` filtered among each page) with their active Prices | `prod_…` / none | not supported |

A cursor is a position, not a search: hand the same `updatedSince` and `query`
with it to the next `FetchProducts`. A provider that filters a page after
reading it (Stripe's `updatedSince`) may give a short or empty page that
still has a next one: page on while `hasMore()`.

Each provider's manual says what it maps how: the `Catalogue` section of omnitrade/stripe,
omnitrade/shopify and omnitrade/woocommerce, the Creators API section of omnitrade/amazon, the
page-reading section of omnitrade/web.

`limit` is capped by each provider: 100 at Stripe and WooCommerce, 250 at Shopify, 10 at Amazon
(at least 1 everywhere). Amazon ignores `cursor` and `updatedSince` and gives no next page.

## Webhooks

A product or stock event goes through `notify()` like a payment's: the
`Notification` then carries `$product` (null for a deletion; `$reference` is
the product's id) or `$stocks`, and `isCatalogue()` is true.

```php
$notification = $gateway->notify($request->getContent(), $request->headers->all());
if ($notification->isCatalogue()) {
    // update the copy: $notification->product, $notification->stocks
}
```

## Affiliate links

`affiliateLink()` gives the address that sends a buyer to a product with the
site's tag, or null when the provider has no partner programme for it:

```php
$registry->get('amazon')->affiliateLink('https://www.amazon.fr/dp/B0C1234567/ref=x');   // https://www.amazon.fr/dp/B0C1234567?tag=mysite-21
$registry->get('web')->affiliateLink('https://www.fnac.com/a1');                        // the parameters configured for fnac.com, or null
```

`omnitrade/amazon` needs only the partner tag for it (no API call);
`omnitrade/web` adds the query parameters its `affiliate` option maps to the
page's host, and drops the tracking parameters the address came with.

<?php

namespace Omnitrade\Model;

/** One thing one can put in a cart: a vintage, a size, a colour of a product. */
final readonly class ProductVariant
{
    public function __construct(
        public string $reference,
        /** null for a product's only variant ("Default Title" at Shopify). */
        public ?string $title = null,
        public ?string $sku = null,
        public ?string $barcode = null,
        /** @var list<Offer> the shop's own first */
        public array $offers = [],
        /** @var array<string, string> its value of each Option, by the option's name */
        public array $options = [],
        public ?Stock $stock = null,
        public ?Media $image = null,
        /** @var array<string, string|list<string>> what else the platform says of it (metafields, meta_data) */
        public array $attributes = [],
        /** Grams. */
        public ?int $weight = null,
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
    ) {
    }

    /** The first offer: the shop's own price. */
    public function offer(): ?Offer
    {
        return $this->offers[0] ?? null;
    }

    public function price(): ?Money
    {
        return $this->offer()?->price;
    }
}

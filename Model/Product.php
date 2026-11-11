<?php

namespace Omnitrade\Model;

/**
 * A product as a platform has it - Shopify, WooCommerce, Stripe Products, a
 * marketplace listing - normalized: its texts, its brand, its key/value
 * attributes, its options, its variants with their offers and stock, its
 * pictures. What the platform answered is kept whole in $raw.
 */
final readonly class Product
{
    public const ACTIVE = 'active';
    public const DRAFT = 'draft';
    public const ARCHIVED = 'archived';

    public function __construct(
        public string $provider,
        /** The platform's id. */
        public string $reference,
        public string $title,
        /** HTML, as the platform holds it. */
        public ?string $description = null,
        /** Its handle or slug there. */
        public ?string $handle = null,
        /** Shopify's vendor, WooCommerce's brand, a "brand" metadata. */
        public ?string $brand = null,
        /** The product page, when there is one. */
        public ?string $url = null,
        /** self::ACTIVE, DRAFT or ARCHIVED. */
        public string $status = self::ACTIVE,
        /** @var list<string> */
        public array $tags = [],
        /** @var list<string> categories, product types, collections - by name */
        public array $categories = [],
        /** @var array<string, string|list<string>> key/value: metafields, metadata, WooCommerce attributes */
        public array $attributes = [],
        /** @var list<Option> */
        public array $options = [],
        /** @var list<ProductVariant> at least one */
        public array $variants = [],
        /** @var list<Media> */
        public array $media = [],
        /** Who sells it, when it is not the shop itself. */
        public ?Merchant $merchant = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
    ) {
    }

    public function isActive(): bool
    {
        return self::ACTIVE === $this->status;
    }

    public function variant(string $reference): ?ProductVariant
    {
        foreach ($this->variants as $variant) {
            if ($variant->reference === $reference) {
                return $variant;
            }
        }

        return null;
    }

    /** The lowest price of its variants' first offers. */
    public function price(): ?Money
    {
        $lowest = null;
        foreach ($this->variants as $variant) {
            $price = $variant->price();
            if (null !== $price && (null === $lowest || $price->amount < $lowest->amount)) {
                $lowest = $price;
            }
        }

        return $lowest;
    }
}

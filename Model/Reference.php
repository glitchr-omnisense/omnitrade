<?php

namespace Omnitrade\Model;

/**
 * Which product is meant: the platform's id for it, or the address of its
 * page (a shop's product page, a marketplace listing). A provider reads the
 * one it understands; of() tells them apart.
 */
final readonly class Reference
{
    public function __construct(
        public ?string $id = null,
        public ?string $url = null,
    ) {
        if ((null === $id || '' === $id) && (null === $url || '' === $url)) {
            throw new \InvalidArgumentException('A reference needs an id or a URL.');
        }
    }

    /** "gid://shopify/Product/42", "prod_Q1x", "1234" or "https://shop.example/products/margaux". */
    public static function of(string|self $idOrUrl): self
    {
        if ($idOrUrl instanceof self) {
            return $idOrUrl;
        }
        $idOrUrl = trim($idOrUrl);

        return preg_match('~^https?://~i', $idOrUrl) ? new self(url: $idOrUrl) : new self(id: $idOrUrl);
    }

    public function isUrl(): bool
    {
        return null === $this->id;
    }

    /** The URL's path, without the query: what a provider matches a handle or a slug in. */
    public function path(): ?string
    {
        return null === $this->url ? null : (parse_url($this->url, \PHP_URL_PATH) ?: null);
    }

    /** The last segment of the URL's path ("margaux-2019" in /products/margaux-2019). */
    public function slug(): ?string
    {
        $path = $this->path();
        if (null === $path) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', $path), static fn ($s) => '' !== $s));

        return $segments ? rawurldecode(end($segments)) : null;
    }

    public function __toString(): string
    {
        return $this->id ?? (string) $this->url; // the constructor makes sure one of them is given
    }
}

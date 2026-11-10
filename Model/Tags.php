<?php

namespace Omnitrade\Model;

use Omnitrade\Exception\InvalidConfigException;

/**
 * Labels a gateway puts on everything it creates at the provider - payments,
 * checkout sessions, refunds, subscriptions, customers, accounts - set once
 * in its configuration (the "tags" option):
 *
 *   'card' => ['factory' => 'stripe', 'options' => ['api_key' => '...', 'tags' => ['site' => 'solstay']]],
 *
 * Several sites sharing one provider account (one Stripe sandbox) tell their
 * objects apart by them: the provider keeps them (Stripe's metadata, PayPal's
 * reference_id, Shopify's custom attributes and tags, WooCommerce's
 * meta_data) and a webhook's event carries them back (Notification::$tags).
 *
 * merge() puts them under the metadata a call carries: the caller's own
 * metadata wins on a key both have, except "site" (SITE), which belongs to
 * the configuration - a payment cannot pass for another site's.
 */
final readonly class Tags
{
    /** The reserved key: the site the gateway works for. */
    public const SITE = 'site';

    /** @param array<string, string> $values */
    public function __construct(public array $values = [])
    {
    }

    /**
     * The "tags" option as given: null or a map of names to scalars (an
     * environment variable gives a string, a number stays a number).
     *
     * @throws InvalidConfigException a list, or a value that is not a scalar
     */
    public static function fromOption(mixed $tags): self
    {
        if (null === $tags || [] === $tags) {
            return new self();
        }
        if (!\is_array($tags) || array_is_list($tags)) {
            throw new InvalidConfigException(\sprintf('The "tags" option is a map of names to values ({site: my-site}), %s given.', get_debug_type($tags)));
        }
        $values = [];
        foreach ($tags as $name => $value) {
            if (null === $value) {
                continue;
            }
            if (!\is_scalar($value) || '' === trim((string) $name)) {
                throw new InvalidConfigException(\sprintf('The "tags" option\'s "%s" is not a name with a text value.', $name));
            }
            $values[(string) $name] = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return new self($values);
    }

    public function isEmpty(): bool
    {
        return [] === $this->values;
    }

    /** The site the gateway works for, when its configuration names one. */
    public function site(): ?string
    {
        $site = $this->values[self::SITE] ?? null;

        return null !== $site && '' !== $site ? $site : null;
    }

    /**
     * The metadata to send with a create call: these tags, the caller's
     * metadata over them, the configured "site" over everything.
     *
     * @param array<array-key, scalar|null> $metadata the caller's (Payment::$metadata...)
     *
     * @return array<string, string>
     */
    public function merge(array $metadata = []): array
    {
        $merged = $this->values;
        foreach ($metadata as $key => $value) {
            if (null !== $value) {
                $merged[(string) $key] = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }
        if (null !== $site = $this->site()) {
            $merged[self::SITE] = $site;
        }

        return $merged;
    }
}

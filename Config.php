<?php

namespace Omnitrade;

use Omnitrade\Exception\InvalidConfigException;
use Omnitrade\Model\Tags;

/**
 * A gateway's configuration while its factory builds it: the options given,
 * the factory's defaults under them, and the keys that must be filled -
 * omnisense/contracts' Config, with omnitrade's prefix ("omnitrade.factory_name",
 * "omnitrade.backoff"...) and its InvalidConfigException. Every gateway takes
 * three options besides its own: "tags" (labels on what it creates),
 * "policy" (the site's tightening of its usage policy) and "usage" (what the
 * site says of itself: commercial, open_source, contract).
 */
final class Config extends \Omnisense\Contracts\Provider\Config
{
    /** @var string */
    public const PREFIX = 'omnitrade';

    /** @param list<string> $keys */
    public function validateNotEmpty(array $keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $key) => !isset($this[$key]) || '' === $this[$key] || [] === $this[$key]));
        if ($missing) {
            throw new InvalidConfigException(\sprintf('The "%s" gateway needs: %s.', $this->string('omnitrade.factory_name') ?? '?', implode(', ', $missing)));
        }
    }

    /** The "tags" option, read (an InvalidConfigException when it is not a map of texts). */
    public function tags(): Tags
    {
        return Tags::fromOption($this->get('tags'));
    }

    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException($message);
    }
}

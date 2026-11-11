<?php

namespace Omnitrade\Request;

/**
 * The provider's page where a connected account's holder gives what is
 * asked (identity, bank account), valid a few minutes, used once. Result:
 * its URL. $returnUrl is where they come back when done or tired (ask
 * FetchAccount what is still missing), $refreshUrl where they land when the
 * link ran out: make a new one there.
 *
 * @extends Request<string>
 */
final class AccountLink extends Request
{
    public const ONBOARDING = 'onboarding';
    public const UPDATE = 'update';

    public function __construct(
        public readonly string $reference,
        public readonly string $returnUrl,
        public readonly string $refreshUrl,
        public readonly string $type = self::ONBOARDING,
    ) {
    }

    public function getUrl(): ?string
    {
        return $this->getResult();
    }
}

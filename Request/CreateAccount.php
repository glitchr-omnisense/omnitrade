<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Account;

/**
 * Open a connected account for somebody the platform sends payments to (a
 * seller, the host of a fund): an Express account by default - the provider
 * holds its dashboard and its identity checks. Result: the Account, not
 * ready yet; AccountLink gives the page where its holder completes it.
 *
 * @extends Request<Account>
 */
final class CreateAccount extends Request
{
    /**
     * @param array<string, scalar> $metadata
     * @param list<string>          $capabilities what it must be able to do: "transfers" (be sent money), "card_payments"
     */
    public function __construct(
        /** ISO 3166-1 alpha-2: where its holder lives. */
        public readonly string $country,
        public readonly ?string $email = null,
        public readonly string $type = Account::EXPRESS,
        /** "individual" or "company"; null: asked during onboarding. */
        public readonly ?string $businessType = null,
        public readonly array $metadata = [],
        public readonly array $capabilities = ['transfers'],
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public function getAccount(): ?Account
    {
        return $this->getResult();
    }
}

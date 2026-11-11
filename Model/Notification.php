<?php

namespace Omnitrade\Model;

/**
 * What a provider is telling us, checked: one event about one transaction.
 * The event is the provider's own name for it; $status is what it means for
 * the transaction when it means anything (null for a hint to ignore), and
 * $transaction the transaction as the event carries it, when it does.
 *
 * A catalogue event (a product created, changed or deleted, a stock level
 * moved) is one too, as are a connected account's ($account) and a
 * subscription's ($subscription: started, renewed, past due, ended): $reference is then the product's id there, $product the
 * product as the event carries it (null for a deletion), $stocks the levels.
 *
 * $tags are the labels the object carries (the gateway's "tags" option when
 * it made it): a site sharing a provider account with others skips the
 * events of theirs - isFromAnotherSite('solstay').
 */
final readonly class Notification
{
    public function __construct(
        public string $provider,
        /** The provider's event type: "checkout.session.completed", "orders/paid"... */
        public string $event,
        /** The transaction (or platform order) it concerns, by its reference there. */
        public ?string $reference = null,
        public ?Status $status = null,
        public ?Transaction $transaction = null,
        /** The provider's id for the event, to handle it once. */
        public ?string $id = null,
        /** @var array<mixed> the provider's answer, whole */
        public array $raw = [],
        public ?Product $product = null,
        /** @var list<Stock> */
        public array $stocks = [],
        /** A connected account's event ("account.updated"): the account as it stands. */
        public ?Account $account = null,
        /** A subscription's event (created, renewed, changed, ended): the subscription as it stands. */
        public ?Subscription $subscription = null,
        /**
         * The tags the event's object carries (Model\Tags: "site" among them
         * when the gateway that made it had one), as the provider gave them back.
         *
         * @var array<string, string>
         */
        public array $tags = [],
    ) {
    }

    /** The site whose gateway made the event's object, when its tags say. */
    public function site(): ?string
    {
        $site = $this->tags[Tags::SITE] ?? null;

        return null !== $site && '' !== $site ? $site : null;
    }

    /**
     * Whether the event is about another site's object: its "site" tag names
     * a site, and not this one. An event without the tag (an object made in
     * the provider's dashboard, a provider that keeps no tag) is not.
     */
    public function isFromAnotherSite(?string $site): bool
    {
        return null !== $site && null !== $this->site() && $this->site() !== $site;
    }

    public function isAccount(): bool
    {
        return null !== $this->account;
    }

    public function isSubscription(): bool
    {
        return null !== $this->subscription;
    }

    /** About the catalogue rather than a payment. */
    public function isCatalogue(): bool
    {
        return null !== $this->product || [] !== $this->stocks || str_starts_with($this->event, 'product') || str_starts_with($this->event, 'inventory');
    }
}

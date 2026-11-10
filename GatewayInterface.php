<?php

namespace Omnitrade;

use Omnisense\Contracts\Provider\ProviderInterface;
use Omnitrade\Model\Account;
use Omnitrade\Model\Money;
use Omnitrade\Model\Notification;
use Omnitrade\Model\Payment;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Model\PlatformOrder;
use Omnitrade\Model\Product;
use Omnitrade\Model\ProductPage;
use Omnitrade\Model\Reference;
use Omnitrade\Model\Refund;
use Omnitrade\Model\Stock;
use Omnitrade\Model\Subscription;
use Omnitrade\Model\Transaction;
use Omnitrade\Request\Request;

/**
 * One provider, configured - a payment service (Stripe, PayPal) or a commerce
 * platform (Shopify, WooCommerce): the same questions for all of them, about
 * payments, the catalogue, connected accounts and subscriptions. Each
 * typed method is a shortcut for execute() with its request; a provider that
 * does not do something throws RequestNotSupportedException, and supports()
 * says so beforehand.
 *
 * It is an omnisense/contracts provider: capabilities() lists the operations
 * it supports and its usage policy (Capabilities).
 */
interface GatewayInterface extends ProviderInterface
{
    public function getName(): string;

    public function getTitle(): string;

    /** @param class-string<Request> $request */
    public function supports(string $request): bool;

    /** The operations it supports, and the provider's usage policy. */
    public function capabilities(): Capabilities;

    /**
     * @template T of Request
     *
     * @param T $request
     *
     * @return T answered
     *
     * @throws Exception\RequestNotSupportedException
     * @throws Exception\ProviderException
     */
    public function execute(Request $request): Request;

    /** Take the money, now or through a page the buyer is sent to (a redirect, then notify()). */
    public function purchase(Payment $payment): Transaction;

    /** Reserve the money; capture() takes it, void() lets it go. */
    public function authorize(Payment $payment): Transaction;

    /** @param Money|null $amount part of the authorization, or all of it */
    public function capture(string $reference, ?Money $amount = null, ?string $idempotencyKey = null): Transaction;

    /** @param Money|null $amount part of the payment, or all of it */
    public function refund(string $reference, ?Money $amount = null, ?string $idempotencyKey = null, ?string $reason = null): Refund;

    public function void(string $reference): Transaction;

    /** The transaction as the provider has it now. */
    public function fetch(string $reference): Transaction;

    /** @return PaymentMethod[] what the provider can take, for this amount and country when given */
    public function paymentMethods(?Money $for = null, ?string $country = null): array;

    /**
     * What the provider is telling us (a webhook): checked against its
     * signature, read as one event about one transaction.
     *
     * @param array<string, string|string[]> $headers
     *
     * @throws Exception\InvalidNotificationException when the signature does not hold
     */
    public function notify(string $body, array $headers = []): Notification;

    /** A platform's own order (Shopify, WooCommerce): the sale as it stands there. */
    public function fetchOrder(string $reference): PlatformOrder;

    /** One product, by the platform's id or the address of its page; null when there is none. */
    public function fetchProduct(Reference|string $reference): ?Product;

    /** A page of the catalogue, from a cursor, changed since a date, matching a search. */
    public function fetchProducts(?string $cursor = null, ?\DateTimeInterface $updatedSince = null, ?string $query = null, int $limit = 50): ProductPage;

    /**
     * @param list<string> $references the variants, by their ids there (none: all)
     *
     * @return list<Stock>
     */
    public function fetchInventory(array $references = []): array;

    /** The address that sends a buyer to a product with the site's affiliate tag; null when there is no programme for it. */
    public function affiliateLink(Reference|string $reference, ?string $tag = null): ?string;

    /**
     * Open a connected account (Express by default) the platform will send payments to.
     *
     * @param array<string, scalar> $metadata
     */
    public function createAccount(string $country, ?string $email = null, string $type = Account::EXPRESS, array $metadata = []): Account;

    /** The provider's page where the account's holder completes it. */
    public function accountLink(string $reference, string $returnUrl, string $refreshUrl): string;

    public function fetchAccount(string $reference): Account;

    /** Start a subscription on a page the buyer is sent to: the payment's amount now, then every interval. */
    public function subscribe(Payment $payment, string $interval = 'month', int $intervalCount = 1, ?string $price = null): Transaction;

    public function fetchSubscription(string $reference): Subscription;

    /** Stop a subscription, at the end of the paid period unless told otherwise. */
    public function cancelSubscription(string $reference, bool $atPeriodEnd = true): Subscription;

    /** The provider's page where a subscriber manages their subscription. */
    public function subscriptionPortal(string $customer, string $returnUrl, ?string $locale = null): string;
}

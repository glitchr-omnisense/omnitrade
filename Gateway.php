<?php

namespace Omnitrade;

use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnitrade\Action\ActionInterface;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Exception\RequestNotSupportedException;
use Omnitrade\Model\Account;
use Omnitrade\Model\Money;
use Omnitrade\Model\Notification;
use Omnitrade\Model\Payment;
use Omnitrade\Model\PlatformOrder;
use Omnitrade\Model\Product;
use Omnitrade\Model\ProductPage;
use Omnitrade\Model\Reference;
use Omnitrade\Model\Refund as RefundModel;
use Omnitrade\Model\Subscription;
use Omnitrade\Model\Transaction;
use Omnitrade\Request;

/**
 * A provider's actions behind one door: the first action supporting a request
 * answers it. The waits the provider asks for (a 429's Retry-After) hold its
 * calls in every case, in its API client. Guarded (guardedBy(), what
 * Registry::guarded() gives), every request but a webhook's (Notify) also
 * goes through the provider's usage policy as the site runs it
 * (omnisense/contracts' PolicyGuard): a limit reached refuses it before
 * anything is sent (UsageLimitReachedException), the call is counted after,
 * answered or failed. Nothing retries a call.
 */
final class Gateway implements GatewayInterface, DeclaresUsagePolicyInterface
{
    /** Each request's operation: GatewayInterface's method name, the policy's "operations". */
    public const OPERATIONS = [
        Request\Purchase::class => 'purchase',
        Request\Authorize::class => 'authorize',
        Request\Capture::class => 'capture',
        Request\Refund::class => 'refund',
        Request\VoidAuthorization::class => 'void',
        Request\FetchTransaction::class => 'fetch',
        Request\GetPaymentMethods::class => 'paymentMethods',
        Request\Notify::class => 'notify',
        Request\FetchOrder::class => 'fetchOrder',
        Request\FetchProduct::class => 'fetchProduct',
        Request\FetchProducts::class => 'fetchProducts',
        Request\FetchInventory::class => 'fetchInventory',
        Request\AffiliateLink::class => 'affiliateLink',
        Request\CreateAccount::class => 'createAccount',
        Request\AccountLink::class => 'accountLink',
        Request\FetchAccount::class => 'fetchAccount',
        Request\Subscribe::class => 'subscribe',
        Request\FetchSubscription::class => 'fetchSubscription',
        Request\CancelSubscription::class => 'cancelSubscription',
        Request\SubscriptionPortal::class => 'subscriptionPortal',
        Request\RegisterWebhook::class => 'registerWebhook',
        Request\FetchWebhooks::class => 'fetchWebhooks',
        Request\DeleteWebhook::class => 'deleteWebhook',
        Request\SetupMethod::class => 'setupMethod',
        Request\FetchSavedMethods::class => 'fetchSavedMethods',
        Request\RemoveSavedMethod::class => 'removeSavedMethod',
        Request\CompleteMethodSetup::class => 'completeMethodSetup',
    ];

    private ?Capabilities $capabilities = null;

    /**
     * @param ActionInterface[] $actions
     * @param ?UsagePolicy      $policy  the provider's, as it declares it (unknown when not given)
     * @param ?PolicyGuard      $guard   the policy every request but a webhook's goes through (none: unguarded)
     */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly array $actions,
        private readonly ?UsagePolicy $policy = null,
        private readonly ?PolicyGuard $guard = null,
    ) {
    }

    /** The same gateway, its requests (but a webhook's) through this guard. */
    public function guardedBy(PolicyGuard $guard): self
    {
        return new self($this->name, $this->title, $this->actions, $this->policy, $guard);
    }

    /** The operation a request is: "purchase", "refund"... (its class's short name, first letter lower, when it is no request of the family's). */
    public static function operation(Request\Request $request): string
    {
        return self::OPERATIONS[$request::class] ?? lcfirst((new \ReflectionClass($request))->getShortName());
    }

    public function usagePolicy(): UsagePolicy
    {
        return $this->policy ?? UsagePolicy::unknown($this->name);
    }

    public function capabilities(): Capabilities
    {
        if (null === $this->capabilities) {
            $operations = [];
            foreach (self::OPERATIONS as $request => $operation) {
                if ($this->supports($request)) {
                    $operations[] = $operation;
                }
            }
            $this->capabilities = new Capabilities($operations, $this->usagePolicy());
        }

        return $this->capabilities;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function supports(string $request): bool
    {
        $probe = (new \ReflectionClass($request))->newInstanceWithoutConstructor();
        foreach ($this->actions as $action) {
            if ($action->supports($probe)) {
                return true;
            }
        }

        return false;
    }

    public function execute(Request\Request $request): Request\Request
    {
        // A webhook is a call made to the site: its own calls to the provider wait as the provider asked, in its API client.
        if (null !== $this->guard && !$request instanceof Request\Notify) {
            return $this->guard->call(self::operation($request), fn () => $this->answer($request));
        }

        return $this->answer($request);
    }

    /**
     * @template T of Request\Request
     *
     * @param T $request
     *
     * @return T
     */
    private function answer(Request\Request $request): Request\Request
    {
        foreach ($this->actions as $action) {
            if ($action->supports($request)) {
                $action->execute($request);
                if (!$request->isAnswered()) {
                    throw new ProviderException($this->name, \sprintf('%s left the request unanswered.', $action::class));
                }

                return $request;
            }
        }

        throw RequestNotSupportedException::for($request, $this->name);
    }

    /**
     * The result of a request that must have one: an action that answered null breaks its
     * contract, a TypeError as the method's return type made it.
     *
     * @template T
     *
     * @param T|null $result
     *
     * @return T
     */
    private static function answered(mixed $result, string $method): mixed
    {
        if (null === $result) {
            throw new \TypeError(\sprintf('%s::%s(): Return value must not be null, the action answered null.', self::class, $method));
        }

        return $result;
    }

    public function purchase(Payment $payment): Transaction
    {
        return self::answered($this->execute(new Request\Purchase($payment))->getTransaction(), __FUNCTION__);
    }

    public function authorize(Payment $payment): Transaction
    {
        return self::answered($this->execute(new Request\Authorize($payment))->getTransaction(), __FUNCTION__);
    }

    public function capture(string $reference, ?Money $amount = null, ?string $idempotencyKey = null): Transaction
    {
        return self::answered($this->execute(new Request\Capture($reference, $amount, $idempotencyKey))->getTransaction(), __FUNCTION__);
    }

    public function refund(string $reference, ?Money $amount = null, ?string $idempotencyKey = null, ?string $reason = null): RefundModel
    {
        return self::answered($this->execute(new Request\Refund($reference, $amount, $idempotencyKey, $reason))->getRefund(), __FUNCTION__);
    }

    public function void(string $reference): Transaction
    {
        return self::answered($this->execute(new Request\VoidAuthorization($reference))->getTransaction(), __FUNCTION__);
    }

    public function fetch(string $reference): Transaction
    {
        return self::answered($this->execute(new Request\FetchTransaction($reference))->getTransaction(), __FUNCTION__);
    }

    public function paymentMethods(?Money $for = null, ?string $country = null): array
    {
        return $this->execute(new Request\GetPaymentMethods($for, $country))->getMethods();
    }

    public function notify(string $body, array $headers = []): Notification
    {
        return self::answered($this->execute(new Request\Notify($body, $headers))->getNotification(), __FUNCTION__);
    }

    public function fetchOrder(string $reference): PlatformOrder
    {
        return self::answered($this->execute(new Request\FetchOrder($reference))->getOrder(), __FUNCTION__);
    }

    public function fetchProduct(Reference|string $reference): ?Product
    {
        return $this->execute(new Request\FetchProduct($reference))->getProduct();
    }

    public function fetchProducts(?string $cursor = null, ?\DateTimeInterface $updatedSince = null, ?string $query = null, int $limit = 50): ProductPage
    {
        return $this->execute(new Request\FetchProducts($cursor, $updatedSince, $query, $limit))->getPage();
    }

    public function fetchInventory(array $references = []): array
    {
        return $this->execute(new Request\FetchInventory($references))->getStocks();
    }

    public function affiliateLink(Reference|string $reference, ?string $tag = null): ?string
    {
        return $this->execute(new Request\AffiliateLink($reference, $tag))->getUrl();
    }

    public function createAccount(string $country, ?string $email = null, string $type = Account::EXPRESS, array $metadata = []): Account
    {
        return self::answered($this->execute(new Request\CreateAccount($country, $email, $type, null, $metadata))->getAccount(), __FUNCTION__);
    }

    public function accountLink(string $reference, string $returnUrl, string $refreshUrl): string
    {
        return self::answered($this->execute(new Request\AccountLink($reference, $returnUrl, $refreshUrl))->getUrl(), __FUNCTION__);
    }

    public function fetchAccount(string $reference): Account
    {
        return self::answered($this->execute(new Request\FetchAccount($reference))->getAccount(), __FUNCTION__);
    }

    public function subscribe(Payment $payment, string $interval = 'month', int $intervalCount = 1, ?string $price = null): Transaction
    {
        return self::answered($this->execute(new Request\Subscribe($payment, $interval, $intervalCount, $price))->getTransaction(), __FUNCTION__);
    }

    public function fetchSubscription(string $reference): Subscription
    {
        return self::answered($this->execute(new Request\FetchSubscription($reference))->getSubscription(), __FUNCTION__);
    }

    public function cancelSubscription(string $reference, bool $atPeriodEnd = true): Subscription
    {
        return self::answered($this->execute(new Request\CancelSubscription($reference, $atPeriodEnd))->getSubscription(), __FUNCTION__);
    }

    public function subscriptionPortal(string $customer, string $returnUrl, ?string $locale = null): string
    {
        return self::answered($this->execute(new Request\SubscriptionPortal($customer, $returnUrl, $locale))->getUrl(), __FUNCTION__);
    }
}

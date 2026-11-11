<?php

namespace Omnitrade\Request;

use Omnitrade\Model\Notification;

/**
 * What the provider sent to the webhook: the raw body and the request's
 * headers, for the signature. Result: the Notification, or
 * InvalidNotificationException when the signature does not hold.
 *
 * @extends Request<Notification>
 */
final class Notify extends Request
{
    /** @param array<string, string|string[]> $headers */
    public function __construct(public readonly string $body, public readonly array $headers = [])
    {
    }

    /** A header, whatever the case it came in. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (0 === strcasecmp($key, $name)) {
                return \is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return null;
    }

    public function getNotification(): ?Notification
    {
        return $this->getResult();
    }
}

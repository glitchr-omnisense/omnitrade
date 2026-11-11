<?php

namespace Omnitrade\Request;

/**
 * A question put to a gateway. The action that supports it answers by
 * setting the result; the gateway hands the request back.
 *
 * @template TResult = mixed what the action answers with
 */
abstract class Request
{
    /** @var TResult|null */
    private mixed $result = null;
    private bool $answered = false;

    /** @param TResult $result */
    public function setResult(mixed $result): void
    {
        $this->result = $result;
        $this->answered = true;
    }

    /** @return TResult|null */
    public function getResult(): mixed
    {
        return $this->result;
    }

    public function isAnswered(): bool
    {
        return $this->answered;
    }
}

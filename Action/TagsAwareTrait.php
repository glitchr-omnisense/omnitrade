<?php

namespace Omnitrade\Action;

use Omnitrade\Model\Tags;

/** TagsAwareInterface's setter, and the tags (none until the factory gives them). */
trait TagsAwareTrait
{
    protected ?Tags $tags = null;

    public function setTags(Tags $tags): void
    {
        $this->tags = $tags;
    }

    /** The gateway's tags; none for an action built outside a factory. */
    protected function tags(): Tags
    {
        return $this->tags ??= new Tags();
    }
}

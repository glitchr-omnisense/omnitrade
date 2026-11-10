<?php

namespace Omnitrade\Action;

use Omnitrade\Model\Tags;

/**
 * An action that creates something at the provider and labels it with the
 * gateway's tags (the "tags" option): GatewayFactory hands them over when it
 * builds the gateway, an empty Tags when none are configured.
 */
interface TagsAwareInterface
{
    public function setTags(Tags $tags): void;
}

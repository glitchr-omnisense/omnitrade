<?php

namespace Omnitrade\Tests;

use Omnitrade\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;

/**
 * The contract every provider's test case uses, here so that PHPStan reads it where it is
 * written (the providers' suites run it): abstract, PHPUnit runs nothing of it.
 */
abstract class ContractTestsCase extends TestCase
{
    use GatewayContractTests;
}

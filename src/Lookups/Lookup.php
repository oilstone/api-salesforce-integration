<?php

namespace Oilstone\ApiSalesforceIntegration\Lookups;

use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

abstract class Lookup
{
    abstract public static function all(): array;
}

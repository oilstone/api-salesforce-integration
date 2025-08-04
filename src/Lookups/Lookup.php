<?php

namespace Oilstone\ApiSalesforceIntegration\Lookups;

use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

abstract class Lookup
{
    abstract public static function fetch(Salesforce $client): array;
}

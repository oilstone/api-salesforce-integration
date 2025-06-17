<?php

namespace Oilstone\ApiSalesforceIntegration\Lookups;

use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

abstract class Lookup
{
    /**
     * The Salesforce object name.
     */
    abstract public static function object(): string;

    /**
     * The field on the Salesforce object containing the picklist.
     */
    abstract public static function field(): string;

    /**
     * Retrieve all picklist values for the lookup.
     */
    public static function fetch(Salesforce $client): array
    {
        return $client->picklistValues(static::object(), static::field());
    }
}

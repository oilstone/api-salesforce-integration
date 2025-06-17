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
     * The record type id for the Salesforce object.
     */
    abstract public static function recordTypeId(): string;

    /**
     * Retrieve all picklist values for the lookup.
     */
    public static function fetch(Salesforce $client): array
    {
        return $client->picklistValues(static::object(), static::recordTypeId(), static::field());
    }
}

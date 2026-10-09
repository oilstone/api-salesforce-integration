<?php

namespace Oilstone\ApiSalesforceIntegration\Lookups;

use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

abstract class PickListLookup extends Lookup
{
    protected const MASTER_RECORD_TYPE_ID = '012000000000000AAA';

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
    public static function recordTypeId(): string
    {
        return static::MASTER_RECORD_TYPE_ID;
    }

    /**
     * Retrieve all picklist values for the lookup.
     */
    public static function fetch(Salesforce $client): array
    {
        return static::normalise(
            $client->picklistValues(static::object(), static::recordTypeId(), static::field())
        );
    }

    /**
     * Lookups are keyed by picklist value with the label as the value. A list of
     * value/label entries, as returned by the Salesforce UI API, is mapped to that shape.
     */
    protected static function normalise(array $values): array
    {
        if (! array_is_list($values) || ! isset($values[0]) || ! is_array($values[0])) {
            return $values;
        }

        return array_column($values, 'label', 'value');
    }
}

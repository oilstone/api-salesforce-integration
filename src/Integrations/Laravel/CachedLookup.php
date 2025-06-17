<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel;

use Illuminate\Support\Facades\Cache;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Lookups\Lookup;

abstract class CachedLookup extends Lookup
{
    /**
     * Cache key for the lookup values.
     */
    protected static function cacheKey(): string
    {
        return 'salesforce.lookup.'.static::object().'.'.static::field();
    }

    /**
     * Cache time to live in seconds.
     */
    protected static function ttl(): int
    {
        return 3600;
    }

    public static function all(?Salesforce $client = null): array
    {
        return Cache::remember(static::cacheKey(), static::ttl(), fn () => parent::all($client));
    }
}

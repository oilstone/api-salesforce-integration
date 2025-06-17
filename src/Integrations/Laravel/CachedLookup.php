<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel;

use Illuminate\Support\Facades\Cache;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Lookups\Lookup;

abstract class CachedLookup extends Lookup
{
    protected static int $ttl = 3600; // Default TTL of 1 hour

    /**
     * Cache key for the lookup values.
     */
    protected static function cacheKey(): string
    {
        return 'salesforce.lookup.'.static::object().'.'.static::recordTypeId().'.'.static::field();
    }

    /**
     * Cache time to live in seconds.
     */
    protected static function ttl(): int
    {
        return static::$ttl;
    }

    public static function all(): array
    {
        $client = app(Salesforce::class);

        if (! $client) {
            throw new \InvalidArgumentException('Salesforce client must be provided.');
        }

        return Cache::remember(static::cacheKey(), static::ttl(), fn () => static::fetch($client));
    }
}

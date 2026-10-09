<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel;

use Illuminate\Support\Facades\Cache;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Lookups\PickListLookup;
use RuntimeException;

abstract class CachedLookup extends PickListLookup
{
    protected static int $ttl = 3600; // Default TTL of 1 hour

    protected static bool $memoise = false;

    protected static function cacheKey(): string
    {
        return 'salesforce.lookup.'.static::object().'.'.static::recordTypeId().'.'.static::field();
    }

    protected static function ttl(): int
    {
        return static::$ttl;
    }

    protected static function recordType(string $name): string
    {
        return config("salesforce.record_types.{$name}")
            ?? throw new RuntimeException("Salesforce record type [{$name}] is not configured.");
    }

    /**
     * Empty results are not cached so a failed or partial response from Salesforce
     * doesn't leave the lookup empty for the full TTL.
     */
    public static function all(): array
    {
        $values = Cache::get(static::cacheKey());

        if (is_array($values)) {
            return $values;
        }

        $values = static::fetch(app(Salesforce::class));

        if ($values !== []) {
            Cache::put(static::cacheKey(), $values, static::ttl());
        }

        return $values;
    }

    public static function flush(): void
    {
        Cache::forget(static::cacheKey());

        parent::flush();
    }
}

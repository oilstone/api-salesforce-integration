<?php

namespace Oilstone\ApiSalesforceIntegration\Cache;

use Psr\SimpleCache\CacheInterface;

class QueryCacheHandler
{
    protected string $prefix = 'salesforce.query.';

    public function __construct(
        protected CacheInterface $cache,
        protected ?int $ttl = null
    ) {}

    public function setTtl(?int $ttl): static
    {
        $this->ttl = $ttl;
        return $this;
    }

    public function remember(string $soql, callable $callback): array
    {
        $key = $this->prefix.md5($soql);

        if ($this->cache->has($key)) {
            $value = $this->cache->get($key);
            if (is_array($value)) {
                return $value;
            }
        }

        $data = $callback();

        $this->cache->set($key, $data, $this->ttl);

        return $data;
    }
}

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

    public function remember(string $soql, callable $callback, array $tags = []): array
    {
        $key = $this->prefix.md5($soql);
        $cache = $this->cache;

        if ($tags && method_exists($cache, 'tags') && method_exists($cache, 'getStore')) {
            $store = $cache->getStore();
            if ($store instanceof \Illuminate\Contracts\Cache\TaggableStore) {
                $cache = $cache->tags($tags);
            }
        }

        if ($cache->has($key)) {
            $value = $cache->get($key);
            if (is_array($value)) {
                return $value;
            }
        }

        $data = $callback();

        $cache->set($key, $data, $this->ttl);

        return $data;
    }
}

<?php

namespace Oilstone\ApiSalesforceIntegration\Cache;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

class QueryCacheHandler
{
    protected string $prefix = 'salesforce.query.';

    public function __construct(
        protected CacheInterface $cache,
        protected ?int $ttl = null,
        protected ?LoggerInterface $logger = null
    ) {}

    public function setLogger(?LoggerInterface $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    public function setTtl(?int $ttl): static
    {
        $this->ttl = $ttl;
        return $this;
    }

    protected function log(string $soql, array $response): void
    {
        if (! $this->logger) {
            return;
        }

        $this->logger->debug('Salesforce request', [
            'q' => $soql,
            'method' => 'GET',
            'url' => 'cache:query',
            'status' => 200,
            'response' => $response,
            'cache' => true,
        ]);
    }

    public function remember(string $soql, callable $callback, array $tags = [], array $options = []): array
    {
        $key = $this->prefix.md5($soql);
        $cache = $this->cache;

        if ($tags && method_exists($cache, 'tags') && method_exists($cache, 'getStore')) {
            $store = $cache->getStore();
            if ($store instanceof \Illuminate\Cache\TaggableStore) {
                $cache = $cache->tags($tags);
            }
        }

        if ($cache->has($key)) {
            $value = $cache->get($key);
            if (is_array($value)) {
                if ($options['log_request'] ?? false) {
                    $this->log($soql, $value);
                }

                return $value;
            }
        }

        $data = $callback();

        $cache->set($key, $data, $this->ttl);

        return $data;
    }

    /**
     * Flush cached queries associated with the provided tags.
     */
    public function flush(array $tags): void
    {
        $cache = $this->cache;

        if ($tags && method_exists($cache, 'tags') && method_exists($cache, 'getStore')) {
            $store = $cache->getStore();

            if ($store instanceof \Illuminate\Cache\TaggableStore) {
                $cache->tags($tags)->flush();
            }
        }
    }
}

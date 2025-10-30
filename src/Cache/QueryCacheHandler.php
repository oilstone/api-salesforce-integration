<?php

namespace Oilstone\ApiSalesforceIntegration\Cache;

use Oilstone\ApiSalesforceIntegration\Query;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

class QueryCacheHandler
{
    protected string $queryPrefix = 'salesforce.query.';
    protected string $entryPrefix = 'salesforce.entry.';
    protected string $queryNamespaceKey = 'salesforce.query.namespace';

    protected bool $skipRetrievalByDefault = false;

    public function __construct(
        protected CacheInterface $cache,
        protected ?int $queryTtl = null,
        protected ?int $entryTtl = null,
        protected ?LoggerInterface $logger = null
    ) {}

    public function setLogger(?LoggerInterface $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    public function setTtl(?int $ttl): static
    {
        return $this->setQueryTtl($ttl);
    }

    public function setQueryTtl(?int $ttl): static
    {
        $this->queryTtl = $ttl;

        return $this;
    }

    public function setEntryTtl(?int $ttl): static
    {
        $this->entryTtl = $ttl;

        return $this;
    }

    public function skipRetrievalByDefault(bool $skip = true): static
    {
        $this->skipRetrievalByDefault = $skip;

        return $this;
    }

    public function remember(string $key, callable $callback, array $tags = [], array $options = []): mixed
    {
        return $this->rememberQuery($key, $callback, $options);
    }

    public function rememberQuery(string $soql, callable $callback, array $options = []): mixed
    {
        $cacheKey = $this->buildQueryCacheKey($soql);

        return $this->rememberWithCache($cacheKey, $callback, $this->queryTtl, $options, 'query', $soql);
    }

    public function rememberEntry(Query $query, string $soql, callable $callback, array $options = []): mixed
    {
        $signature = $query->getConditionSignature();

        if ($signature === []) {
            return $callback();
        }

        $cacheKey = $this->buildEntryCacheKey($query->getObject(), $signature);

        return $this->rememberWithCache(
            $cacheKey,
            $callback,
            $this->entryTtl,
            $options,
            'entry',
            $soql,
            [
                'object' => $query->getObject(),
                'conditions' => $signature,
            ]
        );
    }

    public function forgetEntryByConditions(string $object, array $conditions): void
    {
        $signature = $this->normaliseConditionsInput($conditions);

        if ($signature === []) {
            return;
        }

        $cacheKey = $this->buildEntryCacheKey($object, $signature);

        $this->cache->delete($cacheKey);
    }

    public function flushQueryCache(): void
    {
        $this->cache->set($this->queryNamespaceKey, $this->generateNamespace());
    }

    protected function rememberWithCache(
        string $cacheKey,
        callable $callback,
        ?int $ttl,
        array $options,
        string $type,
        ?string $originalKey = null,
        array $context = []
    ): mixed {
        $skipCache = array_key_exists('skip_cache', $options)
            ? (bool) $options['skip_cache']
            : $this->skipRetrievalByDefault;

        if (! $skipCache && $this->cache->has($cacheKey)) {
            $value = $this->cache->get($cacheKey);

            if ($options['log_request'] ?? false) {
                $this->log($originalKey ?? $cacheKey, $value, $type, $context);
            }

            return $value;
        }

        $value = $callback();

        $this->cache->set($cacheKey, $value, $ttl);

        if ($options['log_request'] ?? false) {
            $this->log($originalKey ?? $cacheKey, $value, $type, $context);
        }

        return $value;
    }

    protected function log(string $key, mixed $response, string $type, array $context = []): void
    {
        if (! $this->logger) {
            return;
        }

        $payload = array_merge($context, [
            'key' => $key,
            'method' => 'GET',
            'url' => 'cache:' . $type,
            'status' => 200,
            'cache' => true,
            'response' => is_array($response) ? $response : ['value' => $response],
        ]);

        if ($type === 'query') {
            $payload['q'] = $key;
        }

        $this->logger->debug('Salesforce request', $payload);
    }

    protected function buildQueryCacheKey(string $key): string
    {
        return $this->queryPrefix . $this->getQueryNamespace() . '.' . md5($key);
    }

    protected function buildEntryCacheKey(string $object, array $signature): string
    {
        $normalised = $this->normaliseArray($signature);

        return $this->entryPrefix . md5(json_encode([
            'object' => $object,
            'conditions' => $normalised,
        ], JSON_THROW_ON_ERROR));
    }

    protected function getQueryNamespace(): string
    {
        $namespace = $this->cache->get($this->queryNamespaceKey);

        if (! is_string($namespace) || $namespace === '') {
            $namespace = $this->generateNamespace();
            $this->cache->set($this->queryNamespaceKey, $namespace);
        }

        return $namespace;
    }

    protected function generateNamespace(): string
    {
        try {
            return bin2hex(random_bytes(10));
        } catch (\Throwable $exception) {
            return (string) microtime(true);
        }
    }

    protected function normaliseConditionsInput(array $conditions): array
    {
        if ($conditions === []) {
            return [];
        }

        if ($this->isConditionSignature($conditions)) {
            return $conditions;
        }

        $signature = [];

        foreach ($conditions as $field => $value) {
            $signature[] = [
                'boolean' => 'and',
                'type' => 'basic',
                'field' => $field,
                'operator' => '=',
                'value' => $value,
            ];
        }

        return $signature;
    }

    protected function isConditionSignature(array $conditions): bool
    {
        if (! isset($conditions[0]) || ! is_array($conditions[0])) {
            return false;
        }

        return array_key_exists('boolean', $conditions[0])
            && array_key_exists('type', $conditions[0]);
    }

    protected function normaliseArray(array $data): array
    {
        $normalised = [];

        $isAssoc = $this->isAssociative($data);

        if ($isAssoc) {
            ksort($data);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->normaliseArray($value);
            } elseif (is_object($value)) {
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format(\DateTimeInterface::ATOM);
                } elseif (method_exists($value, '__toString')) {
                    $value = (string) $value;
                } else {
                    $value = serialize($value);
                }
            }

            if ($isAssoc) {
                $normalised[$key] = $value;
            } else {
                $normalised[] = $value;
            }
        }

        return $normalised;
    }

    protected function isAssociative(array $data): bool
    {
        if (function_exists('array_is_list')) {
            return ! array_is_list($data);
        }

        $keys = array_keys($data);

        return $keys !== array_keys($keys);
    }
}

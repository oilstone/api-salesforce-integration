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
    protected string $entryIndexPrefix = 'salesforce.entry_index.';

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

        $indexableConditions = $this->extractEntryIndexConditions($signature);

        if ($indexableConditions === []) {
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
            ],
            function () use ($query, $indexableConditions, $cacheKey): void {
                $this->registerEntryIndex($query->getObject(), $indexableConditions, $cacheKey);
            },
            function () use ($query, $indexableConditions, $cacheKey): void {
                $this->registerEntryIndex($query->getObject(), $indexableConditions, $cacheKey);
            }
        );
    }

    public function forgetEntryByConditions(string $object, array $conditions): void
    {
        $signature = $this->normaliseConditionsInput($conditions);

        if ($signature === []) {
            return;
        }

        $indexableConditions = $this->extractEntryIndexConditions($signature);

        if ($indexableConditions === []) {
            return;
        }

        $keysToDelete = [];

        foreach ($indexableConditions as $field => $values) {
            foreach ($values as $value) {
                $indexKey = $this->buildEntryIndexKey($object, $field, $value);
                $cacheKeys = $this->cache->get($indexKey);

                if (is_array($cacheKeys)) {
                    foreach ($cacheKeys as $cacheKey) {
                        $keysToDelete[$cacheKey] = true;
                    }
                }

                $this->cache->delete($indexKey);
            }
        }

        foreach (array_keys($keysToDelete) as $cacheKey) {
            $this->cache->delete($cacheKey);
        }
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
        array $context = [],
        ?callable $onCacheHit = null,
        ?callable $onCacheStore = null
    ): mixed {
        $skipCache = array_key_exists('skip_cache', $options)
            ? (bool) $options['skip_cache']
            : $this->skipRetrievalByDefault;

        if (! $skipCache && $this->cache->has($cacheKey)) {
            $value = $this->cache->get($cacheKey);

            if ($onCacheHit) {
                $onCacheHit();
            }

            if ($options['log_request'] ?? false) {
                $this->log($originalKey ?? $cacheKey, $value, $type, $context);
            }

            return $value;
        }

        $value = $callback();

        $this->cache->set($cacheKey, $value, $ttl);

        if ($onCacheStore) {
            $onCacheStore();
        }

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

    protected function buildEntryIndexKey(string $object, string $field, mixed $value): string
    {
        return $this->entryIndexPrefix . md5(json_encode([
            'object' => $object,
            'field' => $field,
            'value' => $this->normaliseValue($value),
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

    protected function extractEntryIndexConditions(array $signature): array
    {
        $conditions = [];

        foreach ($signature as $condition) {
            if (! is_array($condition) || ! isset($condition['type'])) {
                continue;
            }

            if ($condition['type'] === 'nested') {
                $nested = $this->extractEntryIndexConditions($condition['conditions'] ?? []);

                foreach ($nested as $field => $values) {
                    $conditions[$field] = array_merge($conditions[$field] ?? [], $values);
                }

                continue;
            }

            if (! isset($condition['field'])) {
                continue;
            }

            if ($condition['type'] === 'basic' && ($condition['operator'] ?? null) === '=') {
                $conditions[$condition['field']][] = $condition['value'] ?? null;
                continue;
            }

            if ($condition['type'] === 'in') {
                foreach (($condition['values'] ?? []) as $value) {
                    $conditions[$condition['field']][] = $value;
                }
            }
        }

        foreach ($conditions as $field => $values) {
            $normalised = [];

            foreach ($values as $value) {
                $normalised[] = $this->normaliseValue($value);
            }

            $conditions[$field] = array_values(array_unique($normalised, SORT_REGULAR));
        }

        return $conditions;
    }

    protected function registerEntryIndex(string $object, array $conditions, string $cacheKey): void
    {
        foreach ($conditions as $field => $values) {
            foreach ($values as $value) {
                $indexKey = $this->buildEntryIndexKey($object, $field, $value);
                $cacheKeys = $this->cache->get($indexKey);

                if (! is_array($cacheKeys)) {
                    $cacheKeys = [];
                }

                if (! in_array($cacheKey, $cacheKeys, true)) {
                    $cacheKeys[] = $cacheKey;
                }

                $this->cache->set($indexKey, $cacheKeys, $this->entryTtl);
            }
        }
    }

    protected function normaliseValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->normaliseArray($value);
        }

        if (is_object($value)) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format(\DateTimeInterface::ATOM);
            }

            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return serialize($value);
        }

        return $value;
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

<?php

namespace Oilstone\ApiSalesforceIntegration\Cache;

use Oilstone\ApiSalesforceIntegration\Query;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

class QueryCacheHandler
{
    protected string $queryPrefix = 'salesforce.query.';
    protected string $entryPrefix = 'salesforce.entry.';
    protected string $schemaPrefix = 'salesforce.schema.';
    protected string $queryNamespaceKey = 'salesforce.query.namespace';
    protected string $schemaNamespaceKey = 'salesforce.schema.namespace';
    protected string $entryIndexPrefix = 'salesforce.entry_index.';

    protected bool $skipRetrievalByDefault = false;

    public function __construct(
        protected CacheInterface $cache,
        protected ?int $queryTtl = null,
        protected ?int $entryTtl = null,
        protected ?LoggerInterface $logger = null,
        protected ?int $schemaTtl = null,
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

    public function setSchemaTtl(?int $ttl): static
    {
        $this->schemaTtl = $ttl;

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
        return $this->rememberWithNamespace(
            $soql,
            $callback,
            $this->queryPrefix,
            $this->queryNamespaceKey,
            $this->queryTtl,
            'query',
            $options
        );
    }

    public function rememberSchema(string $key, callable $callback, array $options = []): mixed
    {
        return $this->rememberWithNamespace(
            $key,
            $callback,
            $this->schemaPrefix,
            $this->schemaNamespaceKey,
            $this->schemaTtl,
            'schema',
            $options
        );
    }

    public function rememberEntry(Query $query, string $soql, callable $callback, array $options = []): mixed
    {
        $signature = $query->getConditionSignature();

        if ($signature === []) {
            return $callback();
        }

        $indexableFields = $query->getIndexableFields();

        if ($indexableFields === []) {
            $indexableFields = [$query->getIdentifier()];
        }

        $cacheKey = $this->buildEntryCacheKey($query->getObject(), $signature);
        $context = ['object' => $query->getObject(), 'conditions' => $signature];

        $skipRetrieval = $this->shouldSkipRetrieval($options);

        if (! $skipRetrieval && $this->cache->has($cacheKey)) {
            $value = $this->cache->get($cacheKey);

            if ($options['log_request'] ?? false) {
                $this->log($soql, $value, 'entry', $context);
            }

            return $value;
        }

        $value = $callback();

        if ($value === null) {
            if ($options['log_request'] ?? false) {
                $this->log($soql, $value, 'entry', $context);
            }

            return $value;
        }

        $this->cache->set($cacheKey, $value, $this->entryTtl);

        if (is_array($value)) {
            $this->registerEntryIndexFromRecord($query->getObject(), $indexableFields, $value, $cacheKey);
        }

        if ($options['log_request'] ?? false) {
            $this->log($soql, $value, 'entry', $context);
        }

        return $value;
    }

    public function forgetEntryByConditions(string $object, array $conditions): void
    {
        if ($conditions === []) {
            return;
        }

        $keysToDelete = [];

        foreach ($conditions as $field => $value) {
            if (! is_string($field) || $value === null) {
                continue;
            }

            $indexKey = $this->buildEntryIndexKey($object, $field, $value);
            $cacheKeys = $this->cache->get($indexKey);

            if (is_array($cacheKeys)) {
                foreach ($cacheKeys as $cacheKey) {
                    $keysToDelete[$cacheKey] = true;
                }
            }

            $this->cache->delete($indexKey);
        }

        foreach (array_keys($keysToDelete) as $cacheKey) {
            $this->cache->delete($cacheKey);
        }
    }

    public function flushQueryCache(): void
    {
        $this->cache->set($this->queryNamespaceKey, $this->generateNamespace());
    }

    public function flushSchemaCache(): void
    {
        $this->cache->set($this->schemaNamespaceKey, $this->generateNamespace());
    }

    protected function rememberWithNamespace(
        string $originalKey,
        callable $callback,
        string $prefix,
        string $namespaceKey,
        ?int $ttl,
        string $type,
        array $options
    ): mixed {
        $cacheKey = $prefix . $this->getNamespace($namespaceKey) . '.' . md5($originalKey);

        $skipRetrieval = $this->shouldSkipRetrieval($options);

        if (! $skipRetrieval && $this->cache->has($cacheKey)) {
            $value = $this->cache->get($cacheKey);

            if ($options['log_request'] ?? false) {
                $this->log($originalKey, $value, $type);
            }

            return $value;
        }

        $value = $callback();

        $this->cache->set($cacheKey, $value, $ttl);

        if ($options['log_request'] ?? false) {
            $this->log($originalKey, $value, $type);
        }

        return $value;
    }

    protected function shouldSkipRetrieval(array $options): bool
    {
        if (array_key_exists('skip_retrieval', $options)) {
            return (bool) $options['skip_retrieval'];
        }

        return $this->skipRetrievalByDefault;
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

    protected function getNamespace(string $key): string
    {
        $namespace = $this->cache->get($key);

        if (! is_string($namespace) || $namespace === '') {
            $namespace = $this->generateNamespace();
            $this->cache->set($key, $namespace);
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

    protected function registerEntryIndexFromRecord(string $object, array $indexableFields, array $record, string $cacheKey): void
    {
        foreach ($indexableFields as $field) {
            if (! is_string($field) || ! array_key_exists($field, $record)) {
                continue;
            }

            $value = $record[$field];

            if ($value === null) {
                continue;
            }

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

<?php

namespace Oilstone\ApiSalesforceIntegration\Cache;

use Oilstone\ApiSalesforceIntegration\Query;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Three independent cache layers, all invalidated by rotating opaque tokens rather than
 * by deleting keys:
 *
 *  - Query cache: SOQL results. The key folds in a global namespace token plus one token per
 *    Salesforce object the query reads (root object and any included child objects), so a
 *    mutation invalidates only queries that touch the mutated object.
 *  - Entry cache: single records from first()/find(). A lookup is stored as a small pointer
 *    (keyed by conditions, selected fields and the version tokens of any indexable field the
 *    conditions match on) that references the record (keyed by identifier value, selected
 *    fields and the identifier's version token). Rotating a version token orphans every key
 *    that embedded it, so invalidation is a single write with no read-modify-write window.
 *  - Schema cache: describe and picklist payloads under their own namespace token.
 *
 * Tokens are memoised in-process so a request reads each one at most once. Call
 * resetLocalState() at request/job boundaries in long-running processes.
 */
class QueryCacheHandler
{
    protected string $queryPrefix = 'salesforce.query.';
    protected string $entryPrefix = 'salesforce.entry.';
    protected string $schemaPrefix = 'salesforce.schema.';
    protected string $queryNamespaceKey = 'salesforce.query.namespace';
    protected string $queryObjectNamespacePrefix = 'salesforce.query.namespace.';
    protected string $schemaNamespaceKey = 'salesforce.schema.namespace';
    protected string $entryVersionPrefix = 'salesforce.entry.version.';

    /** Reserved key marking a pointer entry; Salesforce field names can never contain "@". */
    protected const POINTER_KEY = '@ref';

    protected bool $skipRetrievalByDefault = false;

    /** Sentinel returned by the cache on a miss, so hit detection is a single round trip. */
    protected object $missing;

    /**
     * In-process token memo, keyed by cache store instance then token key. Static so the
     * per-resource clones of this handler share one view of the tokens within a request.
     *
     * @var array<string, array<string, string>>
     */
    protected static array $tokenMemo = [];

    public function __construct(
        protected CacheInterface $cache,
        protected ?int $queryTtl = null,
        protected ?int $entryTtl = null,
        protected ?LoggerInterface $logger = null,
        protected ?int $schemaTtl = null,
    ) {
        $this->missing = new \stdClass;
    }

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

    /**
     * Forget every memoised token. Call at the start of each request or job in a
     * long-running process so tokens rotated by other processes are picked up.
     */
    public static function resetLocalState(): void
    {
        static::$tokenMemo = [];
    }

    /**
     * Alias of rememberQuery() kept for backwards compatibility.
     */
    public function remember(string $key, callable $callback, array $options = [], array $objects = []): mixed
    {
        return $this->rememberQuery($key, $callback, $options, $objects);
    }

    /**
     * @param  array<int, string>  $objects  Salesforce objects the query reads. Pass the root object
     *                                       and any included child objects; an empty list falls back
     *                                       to the global namespace only.
     */
    public function rememberQuery(string $soql, callable $callback, array $options = [], array $objects = []): mixed
    {
        $tokenKeys = [$this->queryNamespaceKey];

        foreach ($this->normaliseObjects($objects) as $object) {
            $tokenKeys[] = $this->objectNamespaceKey($object);
        }

        $tokens = $this->tokens($tokenKeys);

        $cacheKey = $this->queryPrefix.md5(json_encode([array_values($tokens), $soql], JSON_THROW_ON_ERROR));

        return $this->rememberKey($cacheKey, $soql, $callback, $this->queryTtl, 'query', $options);
    }

    public function rememberSchema(string $key, callable $callback, array $options = []): mixed
    {
        $tokens = $this->tokens([$this->schemaNamespaceKey]);

        $cacheKey = $this->schemaPrefix.reset($tokens).'.'.md5($key);

        return $this->rememberKey($cacheKey, $key, $callback, $this->schemaTtl, 'schema', $options);
    }

    public function rememberEntry(Query $query, string $soql, callable $callback, array $options = []): mixed
    {
        $keys = $this->entryKeys($query);

        if ($keys === null) {
            return $callback();
        }

        $context = ['object' => $keys['object'], 'conditions' => $keys['signature']];
        $shouldLog = (bool) ($options['log_request'] ?? false);

        if (! $this->shouldSkipRetrieval($options)) {
            $cached = $this->readEntry($keys['pointer']);

            if ($cached !== $this->missing) {
                $shouldLog && $this->log($soql, $cached, 'entry', $context);

                return $cached;
            }
        }

        $value = $callback();

        if ($value === null) {
            // Negative results are deliberately not cached.
            $shouldLog && $this->log($soql, $value, 'entry', $context);

            return null;
        }

        $idValue = is_array($value) ? $this->fieldValue($value, $keys['identifier']) : null;

        if ($idValue === null || is_array($idValue) || is_object($idValue)) {
            // No usable identifier in the record: store it inline. It can only be invalidated
            // through the version tokens of the fields it was looked up by, or by TTL.
            $this->cache->set($keys['pointer'], $value, $this->entryTtl);
        } else {
            $recordKey = $this->entryRecordKey($keys['object'], $keys['identifier'], $idValue, $keys['select']);

            $this->cache->setMultiple([
                $recordKey => $value,
                $keys['pointer'] => [self::POINTER_KEY => $recordKey],
            ], $this->entryTtl);
        }

        $shouldLog && $this->log($soql, $value, 'entry', $context);

        return $value;
    }

    /**
     * Cache-only lookup of the entry a first()/find() with this query would return. Never
     * calls Salesforce; null when the query has no conditions or nothing is cached.
     */
    public function getEntry(Query $query): mixed
    {
        $keys = $this->entryKeys($query);

        if ($keys === null) {
            return null;
        }

        $cached = $this->readEntry($keys['pointer']);

        return $cached === $this->missing ? null : $cached;
    }

    /**
     * Resolve the pointer key and the pieces of the record key for a single-record query.
     *
     * @return array{object: string, identifier: string, signature: array, select: array, pointer: string}|null
     */
    protected function entryKeys(Query $query): ?array
    {
        $signature = $query->getConditionSignature();

        if ($signature === []) {
            return null;
        }

        $object = (string) $query->getObject();
        $identifier = $query->getIdentifier();
        $indexableFields = $query->getIndexableFields() ?: [$identifier];
        $select = $this->normaliseArray($query->getSelectSignature());

        $versionKeys = $this->conditionVersionKeys($object, $query->getConditions(), $indexableFields);
        $versionTokens = $versionKeys ? array_values($this->tokens($versionKeys, $this->entryTtl)) : [];

        $pointer = $this->entryPrefix.'ptr.'.md5(json_encode([
            'object' => strtolower($object),
            'conditions' => $this->normaliseArray($signature),
            'select' => $select,
            'versions' => $versionTokens,
        ], JSON_THROW_ON_ERROR));

        return compact('object', 'identifier', 'signature', 'select', 'pointer');
    }

    /**
     * Follow a pointer to its record, or return the inline value. Returns the miss sentinel
     * when neither is present.
     */
    protected function readEntry(string $pointerKey): mixed
    {
        $pointer = $this->cache->get($pointerKey, $this->missing);

        if (is_array($pointer) && isset($pointer[self::POINTER_KEY]) && count($pointer) === 1) {
            return $this->cache->get($pointer[self::POINTER_KEY], $this->missing);
        }

        return $pointer;
    }

    /**
     * Invalidate every cached entry that was looked up by, or resolved to a record carrying,
     * the given field values.
     *
     * @param  array<string, mixed|array<int, mixed>>  $conditions  field => value, or field => list of values
     */
    public function forgetEntryByConditions(string $object, array $conditions): void
    {
        $keys = [];

        foreach ($conditions as $field => $values) {
            if (! is_string($field)) {
                continue;
            }

            $values = is_array($values) && array_is_list($values) ? $values : [$values];

            foreach ($values as $value) {
                if ($value === null) {
                    continue;
                }

                $keys[] = $this->entryVersionKey($object, $field, $value);
            }
        }

        if ($keys) {
            $this->rotate($keys, $this->entryTtl);
        }
    }

    /**
     * Invalidate every cached query, regardless of object.
     */
    public function flushQueryCache(): void
    {
        $this->rotate([$this->queryNamespaceKey]);
    }

    /**
     * Invalidate cached queries that read any of the given objects.
     *
     * @param  string|array<int, string>  $objects
     */
    public function flushQueryCacheForObject(string|array $objects): void
    {
        $keys = array_map(
            fn (string $object) => $this->objectNamespaceKey($object),
            $this->normaliseObjects((array) $objects)
        );

        if ($keys) {
            $this->rotate($keys);
        }
    }

    public function flushSchemaCache(): void
    {
        $this->rotate([$this->schemaNamespaceKey]);
    }

    protected function rememberKey(
        string $cacheKey,
        string $logKey,
        callable $callback,
        ?int $ttl,
        string $type,
        array $options
    ): mixed {
        $shouldLog = (bool) ($options['log_request'] ?? false);

        if (! $this->shouldSkipRetrieval($options)) {
            $value = $this->cache->get($cacheKey, $this->missing);

            if ($value !== $this->missing) {
                $shouldLog && $this->log($logKey, $value, $type);

                return $value;
            }
        }

        $value = $callback();

        $this->cache->set($cacheKey, $value, $ttl);

        $shouldLog && $this->log($logKey, $value, $type);

        return $value;
    }

    /**
     * Resolve tokens for the given keys, reading any that are not memoised in one round trip
     * and creating any that do not exist yet.
     *
     * @param  array<int, string>  $keys
     * @return array<string, string>  key => token, in the order given
     */
    protected function tokens(array $keys, ?int $ttl = null): array
    {
        $keys = array_values(array_unique($keys));
        $memo = &static::$tokenMemo[$this->memoKey()];
        $memo ??= [];

        $unknown = array_values(array_filter($keys, static fn (string $key) => ! isset($memo[$key])));

        if ($unknown !== []) {
            $fetched = $this->cache->getMultiple($unknown, $this->missing);
            $create = [];

            foreach ($fetched as $key => $value) {
                if (is_string($value) && $value !== '') {
                    $memo[$key] = $value;
                }
            }

            foreach ($unknown as $key) {
                if (! isset($memo[$key])) {
                    $create[$key] = $this->generateNamespace();
                }
            }

            if ($create !== []) {
                $this->cache->setMultiple($create, $ttl);

                foreach ($create as $key => $token) {
                    $memo[$key] = $token;
                }
            }
        }

        $tokens = [];

        foreach ($keys as $key) {
            $tokens[$key] = $memo[$key];
        }

        return $tokens;
    }

    /**
     * Replace the tokens under the given keys with fresh values, in one round trip.
     *
     * @param  array<int, string>  $keys
     */
    protected function rotate(array $keys, ?int $ttl = null): void
    {
        $memo = &static::$tokenMemo[$this->memoKey()];
        $memo ??= [];

        $values = [];

        foreach (array_unique($keys) as $key) {
            $values[$key] = $memo[$key] = $this->generateNamespace();
        }

        if ($values !== []) {
            $this->cache->setMultiple($values, $ttl);
        }
    }

    protected function memoKey(): string
    {
        return (string) spl_object_id($this->cache);
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
            'url' => 'cache:'.$type,
            'status' => 200,
            'cache' => true,
            'response' => is_array($response) ? $response : ['value' => $response],
        ]);

        if ($type === 'query') {
            $payload['q'] = $key;
        }

        $this->logger->debug('Salesforce request', $payload);
    }

    /**
     * Version-token keys for every indexable field the conditions constrain by equality.
     *
     * @return array<int, string>
     */
    protected function conditionVersionKeys(string $object, array $conditions, array $indexableFields): array
    {
        $keys = [];

        foreach ($conditions as $condition) {
            if (($condition['type'] ?? null) !== 'basic' || ($condition['operator'] ?? null) !== '=') {
                continue;
            }

            $field = $this->matchField((string) $condition['field'], $indexableFields);
            $value = $condition['value'] ?? null;

            if ($field === null || $value === null || is_array($value)) {
                continue;
            }

            $keys[] = $this->entryVersionKey($object, $field, $value);
        }

        return array_values(array_unique($keys));
    }

    protected function entryRecordKey(string $object, string $identifier, mixed $idValue, array $select): string
    {
        $tokens = $this->tokens([$this->entryVersionKey($object, $identifier, $idValue)], $this->entryTtl);

        return $this->entryPrefix.'rec.'.md5(json_encode([
            'object' => strtolower($object),
            'identifier' => strtolower($identifier),
            'id' => $this->normaliseScalar($idValue),
            'select' => $select,
            'version' => reset($tokens),
        ], JSON_THROW_ON_ERROR));
    }

    protected function entryVersionKey(string $object, string $field, mixed $value): string
    {
        return $this->entryVersionPrefix.md5(json_encode([
            strtolower(trim($object)),
            strtolower(trim($field)),
            $this->normaliseScalar($value),
        ], JSON_THROW_ON_ERROR));
    }

    protected function objectNamespaceKey(string $object): string
    {
        return $this->queryObjectNamespacePrefix.strtolower(trim($object));
    }

    /**
     * @return array<int, string>  lower-cased, de-duplicated object names
     */
    protected function normaliseObjects(array $objects): array
    {
        $objects = array_filter(array_map(
            static fn ($object) => is_string($object) ? strtolower(trim($object, " /")) : '',
            $objects
        ));

        return array_values(array_unique($objects));
    }

    protected function matchField(string $field, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && strcasecmp($candidate, $field) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    protected function fieldValue(array $record, string $field): mixed
    {
        if (array_key_exists($field, $record)) {
            return $record[$field];
        }

        foreach ($record as $key => $value) {
            if (is_string($key) && strcasecmp($key, $field) === 0) {
                return $value;
            }
        }

        return null;
    }

    protected function generateNamespace(): string
    {
        try {
            return bin2hex(random_bytes(10));
        } catch (\Throwable) {
            return (string) microtime(true);
        }
    }

    /**
     * Canonical string form of a scalar-ish value so "001" and 001 share a token.
     */
    protected function normaliseScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($this->normaliseArray($value), JSON_THROW_ON_ERROR);
        }

        if (is_object($value)) {
            $value = $this->normaliseValue($value);

            return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
        }

        return (string) $value;
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
        $isList = array_is_list($data);

        if (! $isList) {
            ksort($data);
        }

        $normalised = [];

        foreach ($data as $key => $value) {
            $value = $this->normaliseValue($value);

            if ($isList) {
                $normalised[] = $value;
            } else {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }
}

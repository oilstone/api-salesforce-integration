<?php

namespace Oilstone\ApiSalesforceIntegration;

use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Exceptions\RecordNotFoundException;

class Repository
{
    protected array $indexableFields;

    /**
     * Whether writes re-read the record from Salesforce. When enabled the write and the
     * read share one composite HTTP request; when disabled writes return the payload merged
     * with the identifier, without server-computed values.
     */
    protected bool $refreshAfterWrite = true;

    public function __construct(
        protected string $object,
        protected array $defaultConstraints = [],
        protected array $defaultIncludes = [],
        protected array $defaultValues = [],
        protected string $defaultIdentifier = 'Id',
        protected ?QueryCacheHandler $cacheHandler = null,
        protected ?Salesforce $client = null,
        array $indexableFields = [],
    ) {
        $this->indexableFields = $indexableFields !== []
            ? array_values(array_unique($indexableFields))
            : [$defaultIdentifier];
    }

    public function setDefaultConstraints(array $constraints): static
    {
        $this->defaultConstraints = $constraints;

        return $this;
    }

    public function setDefaultIncludes(array $includes): static
    {
        $this->defaultIncludes = $includes;

        return $this;
    }

    public function setIdentifier(string $identifier): static
    {
        $previous = $this->defaultIdentifier;
        $this->defaultIdentifier = $identifier;

        $position = array_search($previous, $this->indexableFields, true);

        if ($position !== false) {
            $this->indexableFields[$position] = $identifier;
            $this->indexableFields = array_values(array_unique($this->indexableFields));
        } elseif (! in_array($identifier, $this->indexableFields, true)) {
            array_unshift($this->indexableFields, $identifier);
        }

        return $this;
    }

    public function setDefaultValues(array $values): static
    {
        $this->defaultValues = $values;

        return $this;
    }

    public function getDefaultValues(): array
    {
        return $this->defaultValues;
    }

    public function getIdentifier(): string
    {
        return $this->defaultIdentifier;
    }

    public function setRefreshAfterWrite(bool $refresh): static
    {
        $this->refreshAfterWrite = $refresh;

        return $this;
    }

    public function refreshesAfterWrite(): bool
    {
        return $this->refreshAfterWrite;
    }

    public function setCacheHandler(QueryCacheHandler $handler): static
    {
        $this->cacheHandler = $handler;

        return $this;
    }

    public function setClient(Salesforce $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function setIndexableFields(array $fields): static
    {
        $fields = array_values(array_unique(array_filter($fields, 'is_string')));

        if (! in_array($this->defaultIdentifier, $fields, true)) {
            array_unshift($fields, $this->defaultIdentifier);
        }

        $this->indexableFields = $fields;

        return $this;
    }

    public function getIndexableFields(): array
    {
        return $this->indexableFields;
    }

    public function newQuery(?string $object = null): Query
    {
        $query = new Query($object ?? $this->object, $this->getClient(), $this->defaultIdentifier);

        if ($this->cacheHandler) {
            $query->setCacheHandler($this->cacheHandler);
        }

        $query->setIndexableFields($this->indexableFields);

        foreach ($this->defaultConstraints as $constraint) {
            if (is_array($constraint)) {
                $query->where(...$constraint);

                continue;
            }

            if (is_callable($constraint)) {
                $constraint($query);

                continue;
            }

            $query->where($constraint);
        }

        foreach ($this->defaultIncludes as $include) {
            $query->with($include);
        }

        return $query;
    }

    protected function applyOptions(Query $query, array $options): Query
    {
        foreach ($options['conditions'] ?? [] as $condition) {
            if (is_callable($condition)) {
                $condition($query);

                continue;
            }

            if (is_array($condition)) {
                $query->where(...$condition);
            }
        }

        if (isset($options['select'])) {
            $query->select($options['select']);
        }

        foreach ($options['includes'] ?? ($options['with'] ?? []) as $include) {
            $query->with($include);
        }

        foreach ($options['order'] ?? ($options['sort'] ?? []) as $order) {
            if (is_array($order)) {
                $query->orderBy($order[0], $order[1] ?? 'ASC');
            } else {
                $query->orderBy($order);
            }
        }

        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        if (isset($options['offset'])) {
            $query->offset($options['offset']);
        }

        if (isset($options['skip_retrieval'])) {
            $query->setCacheOptions(['skip_retrieval' => $options['skip_retrieval']]);
        }

        return $query;
    }

    public function find(string|array $idConditionsOrOptions, array $options = []): ?array
    {
        $query = $this->newQuery();

        $id = null;
        $conditions = [];

        if (is_array($idConditionsOrOptions)) {
            if ($options === [] && $this->isOptionsArray($idConditionsOrOptions)) {
                $options = $idConditionsOrOptions;
            } else {
                $conditions = $idConditionsOrOptions;
            }
        } else {
            $id = $idConditionsOrOptions;
        }

        $options['select'] = $options['select'] ?? ['FIELDS(ALL)'];

        if ($id !== null) {
            $query->where($this->defaultIdentifier, $id);

        }

        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $this->applyOptions($query, $options)->first();
    }

    public function findOrFail(string|array $idConditionsOrOptions, array $options = []): array
    {
        $record = $this->find($idConditionsOrOptions, $options);

        if (! $record) {
            throw new RecordNotFoundException;
        }

        return $record;
    }

    public function first(array $conditionsOrOptions = [], array $options = []): ?array
    {
        if ($options === [] && $this->isOptionsArray($conditionsOrOptions)) {
            $options = $conditionsOrOptions;
            $conditions = [];
        } else {
            $conditions = $conditionsOrOptions;
        }

        $options['select'] = $options['select'] ?? ['FIELDS(ALL)'];

        $query = $this->newQuery();

        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $this->applyOptions($query, $options)->first();
    }

    public function firstOrFail(array $conditionsOrOptions = [], array $options = []): array
    {
        $record = $this->first($conditionsOrOptions, $options);

        if (! $record) {
            throw new RecordNotFoundException;
        }

        return $record;
    }

    public function get(array $conditionsOrOptions = [], array $options = []): array
    {
        if ($options === [] && $this->isOptionsArray($conditionsOrOptions)) {
            $options = $conditionsOrOptions;
            $conditions = [];
        } else {
            $conditions = $conditionsOrOptions;
        }

        $options['select'] = $options['select'] ?? [$this->defaultIdentifier];

        $query = $this->newQuery();

        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $this->applyOptions($query, $options)->get();
    }

    public function pluck(string $column, ?string $index = null, array $conditionsOrOptions = [], array $options = []): array
    {
        if ($options === [] && $this->isOptionsArray($conditionsOrOptions)) {
            $options = $conditionsOrOptions;
            $conditions = [];
        } else {
            $conditions = $conditionsOrOptions;
        }

        $query = $this->newQuery()->select([]);

        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $this->applyOptions($query, $options)->pluck($column, $index);
    }

    public function count(array $conditionsOrOptions = [], array $options = []): int
    {
        if ($options === [] && $this->isOptionsArray($conditionsOrOptions)) {
            $options = $conditionsOrOptions;
            $conditions = [];
        } else {
            $conditions = $conditionsOrOptions;
        }

        $query = $this->newQuery();

        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $this->applyOptions($query, $options)->count();
    }

    /**
     * @param  array  $options  'refresh' (bool) overrides the repository default; 'select' and
     *                          'includes' shape the re-read when refreshing.
     */
    public function create(array $attributes, array $options = []): array
    {
        $payload = array_replace_recursive($this->defaultValues, $attributes);
        $payload = $this->filterNullDefaults($payload, $attributes);

        $client = $this->getClient();

        if (! $this->shouldRefresh($options)) {
            $result = $client->create($this->object, $payload);

            $this->invalidateAfterMutation([], null, $payload);

            return $this->localRecord($payload, isset($result['id']) ? ['Id' => $result['id']] : []);
        }

        // The new Id is only known inside the composite request, so the re-read references it.
        [$result, $record] = $this->writeWithRefresh(
            $client->createSubrequest('write', $this->object, $payload),
            ['Id' => Query::raw("'@{write.id}'")],
            $options
        );

        $this->invalidateAfterMutation([], null, $payload);

        if ($record === null) {
            throw new RecordNotFoundException;
        }

        if (isset($result['id'])) {
            $this->seedEntryCache(['Id' => $result['id']], $record, $options);
        }

        return $record;
    }

    /**
     * @param  array  $options  'refresh' (bool) overrides the repository default; 'select' and
     *                          'includes' shape the re-read when refreshing.
     */
    public function update(string $id, array $attributes, array $options = []): array
    {
        $payload = array_replace_recursive($this->defaultValues, $attributes);
        $payload = $this->filterNullDefaults($payload, $attributes);

        $lookup = [$this->defaultIdentifier => $id];

        $stale = $this->captureStaleForInvalidation($lookup);

        $client = $this->getClient();

        if (! $this->shouldRefresh($options)) {
            $client->update($this->object, $id, $payload, $this->defaultIdentifier);

            $this->invalidateAfterMutation($lookup, $stale, $payload);

            return $this->localRecord($payload, $lookup);
        }

        [, $record] = $this->writeWithRefresh(
            $client->updateSubrequest('write', $this->object, $id, $payload, $this->defaultIdentifier),
            $lookup,
            $options
        );

        $this->invalidateAfterMutation($lookup, $stale, $payload);

        if ($record === null) {
            throw new RecordNotFoundException;
        }

        $this->seedEntryCache($lookup, $record, $options);

        return $record;
    }

    /**
     * Upsert a record against a single identifier field using Salesforce's native upsert.
     *
     * Issues a PATCH to the sObject rows endpoint keyed on the identifier (the external-id endpoint
     * when the identifier is not Id), so the match and write happen atomically in one request. When
     * the identifier is an External ID field this is safe under concurrency: simultaneous callers
     * converge on a single record rather than racing to create duplicates. Prefer this over upsert()
     * whenever the match is a single External ID (or Id) field.
     *
     * @param  string  $identifierValue  Value to match on the identifier field.
     * @param  array<string, mixed>  $attributes  Attributes to write on insert or update.
     * @param  string|null  $identifier  Field to match on; defaults to the repository identifier
     *                                   (Id). Must be an External ID or Id field.
     */
    public function upsertByIdentifier(string $identifierValue, array $attributes, ?string $identifier = null, array $options = []): array
    {
        $payload = array_replace_recursive($this->defaultValues, $attributes);
        $payload = $this->filterNullDefaults($payload, $attributes);

        $identifier ??= $this->defaultIdentifier;
        $lookup = [$identifier => $identifierValue];

        $stale = $this->captureStaleForInvalidation($lookup);

        $client = $this->getClient();

        if (! $this->shouldRefresh($options)) {
            $result = $client->upsert($this->object, $identifierValue, $payload, $identifier);

            $this->invalidateAfterMutation($lookup, $stale, $payload);

            return $this->localRecord($payload, array_merge($lookup, isset($result['id']) ? ['Id' => $result['id']] : []));
        }

        [, $record] = $this->writeWithRefresh(
            $client->updateSubrequest('write', $this->object, $identifierValue, $payload, $identifier),
            $lookup,
            $options
        );

        $this->invalidateAfterMutation($lookup, $stale, $payload);

        if ($record === null) {
            throw new RecordNotFoundException;
        }

        $this->seedEntryCache($lookup, $record, $options);

        return $record;
    }

    /**
     * Upsert a record by matching on arbitrary conditions.
     *
     * This is a non-atomic query-then-write: it looks up an existing record matching every condition
     * and updates it, or creates one when none is found. Because the lookup and the write are
     * separate requests it is NOT safe under concurrency — two callers can both find nothing and both
     * create, producing duplicates. Use it only for matches Salesforce's native upsert cannot
     * express: multi-field conditions, or a single field that is not an External ID.
     *
     * When matching on a single External ID (or Id) field, use upsertByIdentifier() instead, which
     * performs Salesforce's native, atomic upsert.
     *
     * @param  array<string, mixed>  $conditions  Field => value pairs to match an existing record.
     * @param  array<string, mixed>  $attributes  Attributes to write on update or create.
     */
    public function upsert(array $conditions, array $attributes, array $options = []): array
    {
        $existing = $this->first($conditions, ['select' => [$this->defaultIdentifier], 'skip_retrieval' => true]);

        if ($existing) {
            return $this->update($existing[$this->defaultIdentifier], $attributes, $options);
        }

        return $this->create(array_replace_recursive($attributes, $conditions), $options);
    }

    public function delete(string $id): array
    {
        $stale = $this->captureStaleForInvalidation([$this->defaultIdentifier => $id]);

        $result = $this->getClient()->delete($this->object, $id, $this->defaultIdentifier);

        $this->invalidateAfterMutation([$this->defaultIdentifier => $id], $stale);

        return $result;
    }

    public function getById(string $id, array $options = []): ?array
    {
        return $this->find($id, $options);
    }

    public function firstOrCreate(array $attributes, array $extra = [], array $options = []): array
    {
        $query = $this->newQuery();

        foreach ($attributes as $field => $value) {
            $query->where($field, $value);
        }

        $record = $this->applyOptions($query, array_merge(
            ['select' => ['FIELDS(ALL)']],
            array_intersect_key($options, array_flip(['select', 'includes', 'with'])),
            ['skip_retrieval' => true]
        ))->first();

        if ($record) {
            return $record;
        }

        return $this->create(array_merge($attributes, $extra), $options);
    }

    public function updateOrCreate(array $attributes, array $values = [], array $options = []): array
    {
        $query = $this->newQuery();

        foreach ($attributes as $field => $value) {
            $query->where($field, $value);
        }

        $record = $this->applyOptions($query, [
            'select' => [$this->defaultIdentifier],
            'skip_retrieval' => true,
        ])->first();

        if ($record) {
            return $this->update($record[$this->defaultIdentifier], $values, $options);
        }

        return $this->create(array_merge($attributes, $values), $options);
    }

    protected function isOptionsArray(array $data): bool
    {
        $optionKeys = ['conditions', 'select', 'includes', 'with', 'order', 'sort', 'limit', 'offset', 'skip_retrieval', 'refresh'];

        return (bool) array_intersect(array_keys($data), $optionKeys);
    }

    protected function getClient(): Salesforce
    {
        return $this->client ?? app(Salesforce::class);
    }

    protected function shouldRefresh(array $options): bool
    {
        if (array_key_exists('refresh', $options)) {
            return (bool) $options['refresh'];
        }

        return $this->refreshAfterWrite;
    }

    /**
     * Build the query used to re-read a record after a write: the repository defaults plus
     * the lookup, selecting every field unless the options narrow it.
     */
    protected function refreshQuery(array $lookup, array $options): Query
    {
        $query = $this->newQuery();

        foreach ($lookup as $field => $value) {
            $query->where($field, $value);
        }

        $refreshOptions = array_intersect_key($options, array_flip(['select', 'includes', 'with']));
        $refreshOptions['select'] = $refreshOptions['select'] ?? ['FIELDS(ALL)'];

        return $this->applyOptions($query, $refreshOptions)->limit(1);
    }

    /**
     * Run a write and re-read the record in a single composite HTTP request.
     *
     * @return array{0: array, 1: ?array}  the write response body and the re-read record
     */
    protected function writeWithRefresh(array $writeSubrequest, array $lookup, array $options): array
    {
        $client = $this->getClient();

        $results = $client->composite([
            $writeSubrequest,
            $client->querySubrequest('refresh', $this->refreshQuery($lookup, $options)->toSoql()),
        ]);

        $write = is_array($results['write']['body'] ?? null) ? $results['write']['body'] : [];
        $record = $results['refresh']['body']['records'][0] ?? null;

        return [$write, is_array($record) ? $record : null];
    }

    /**
     * Store a freshly read record in the entry cache under the same key a later find()/first()
     * with the given lookup and options would use, so the write does not cause a cache miss.
     */
    protected function seedEntryCache(array $lookup, array $record, array $options): void
    {
        if (! $this->cacheHandler) {
            return;
        }

        $query = $this->refreshQuery($lookup, $options);

        $this->cacheHandler->rememberEntry($query, $query->toSoql(), static fn () => $record, ['skip_retrieval' => true]);
    }

    /**
     * The record as known locally after a write without a refresh: the identity merged with
     * the payload. Server-computed fields are absent.
     */
    protected function localRecord(array $payload, array $identity): array
    {
        return array_merge($identity, $payload);
    }

    protected function captureStaleForInvalidation(array $lookup): ?array
    {
        if (! $this->cacheHandler || ! $this->hasNonDefaultIndexableFields()) {
            return null;
        }

        return $this->first($lookup);
    }

    /**
     * Invalidate cached queries for this object and every entry that could resolve to the
     * mutated record: the lookup used for the write, plus the old (stale) and new (payload)
     * values of every indexable field. Each value is one token rotation, so this is cheap.
     *
     * @param  array<string, mixed>  $lookup  field => value the write was keyed on, if any
     */
    protected function invalidateAfterMutation(array $lookup, ?array $stale = null, array $payload = []): void
    {
        if (! $this->cacheHandler) {
            return;
        }

        $this->cacheHandler->flushQueryCacheForObject($this->object);

        $conditions = [];

        foreach ($lookup as $field => $value) {
            if (is_string($field) && $value !== null && ! is_array($value)) {
                $conditions[$field][] = $value;
            }
        }

        foreach ($this->indexableFields as $field) {
            foreach ([$stale[$field] ?? null, $payload[$field] ?? null] as $value) {
                if ($value !== null && ! is_array($value)) {
                    $conditions[$field][] = $value;
                }
            }
        }

        if ($conditions === []) {
            return;
        }

        $this->cacheHandler->forgetEntryByConditions(
            $this->object,
            array_map(static fn (array $values) => array_values(array_unique($values, SORT_REGULAR)), $conditions)
        );
    }

    protected function hasNonDefaultIndexableFields(): bool
    {
        foreach ($this->indexableFields as $field) {
            if ($field !== $this->defaultIdentifier) {
                return true;
            }
        }

        return false;
    }

    protected function filterNullDefaults(array $payload, array $attributes): array
    {
        foreach ($payload as $key => $value) {
            $attributeExists = array_key_exists($key, $attributes);

            if (is_array($value)) {
                $attrValue = $attributeExists && is_array($attributes[$key]) ? $attributes[$key] : [];
                $payload[$key] = $this->filterNullDefaults($value, $attrValue);

                if ($payload[$key] === [] && ! $attributeExists) {
                    unset($payload[$key]);
                }

                continue;
            }

            if (($value === null || $value === '') && ! $attributeExists) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }
}

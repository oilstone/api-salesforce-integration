<?php

namespace Oilstone\ApiSalesforceIntegration;

use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Exceptions\RecordNotFoundException;
use Oilstone\ApiSalesforceIntegration\Exceptions\SalesforceCollectionException;

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
        ?array $defaultSelect = null,
    ) {
        $this->indexableFields = $indexableFields !== []
            ? array_values(array_unique($indexableFields))
            : [$defaultIdentifier];

        $this->setDefaultSelect($defaultSelect);
    }

    /**
     * Fields selected by single-record reads (find, first, firstOrCreate and the re-read
     * after a write) when the caller gives no select. Null means FIELDS(ALL), which pulls
     * every column including long text areas; set an explicit list wherever the consumer
     * only needs known fields.
     *
     * @var array<int, string>|null
     */
    protected ?array $defaultSelect = null;

    public function setDefaultSelect(?array $fields): static
    {
        if ($fields !== null) {
            $fields = array_values(array_unique(array_filter(
                array_map(static fn ($field) => is_string($field) ? trim($field) : '', $fields)
            )));

            $fields = $fields === [] ? null : $fields;
        }

        $this->defaultSelect = $fields;

        return $this;
    }

    public function getDefaultSelect(): ?array
    {
        return $this->defaultSelect;
    }

    /**
     * The select for a single-record read: the caller's choice, then the repository default,
     * then everything.
     */
    protected function singleRecordSelect(array $options): array
    {
        return $options['select'] ?? $this->defaultSelect ?? ['FIELDS(ALL)'];
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

        $options['select'] = $this->singleRecordSelect($options);

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

        $options['select'] = $this->singleRecordSelect($options);

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
        $refresh = $this->shouldRefresh($options);

        // The new Id is only known inside the composite request, so the re-read references it.
        [, $result, $record] = $this->performWrite(
            $client->createSubrequest('write', $this->object, $payload),
            fn () => $client->create($this->object, $payload),
            null,
            $refresh ? ['Id' => Query::raw("'@{write.id}'")] : null,
            $options
        );

        $this->invalidateAfterMutation([], null, $payload);

        $identity = isset($result['id']) ? ['Id' => $result['id']] : [];

        if (! $refresh) {
            return $this->localRecord($payload, $identity);
        }

        if ($record === null) {
            throw new RecordNotFoundException;
        }

        if ($identity !== []) {
            $this->seedEntryCache($identity, $record, $options);
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
        $client = $this->getClient();
        $refresh = $this->shouldRefresh($options);

        [$stale, , $record] = $this->performWrite(
            $client->updateSubrequest('write', $this->object, $id, $payload, $this->defaultIdentifier),
            fn () => $client->update($this->object, $id, $payload, $this->defaultIdentifier),
            $lookup,
            $refresh ? $lookup : null,
            $options
        );

        $this->invalidateAfterMutation($lookup, $stale, $payload);

        if (! $refresh) {
            return $this->localRecord($payload, $lookup);
        }

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
        $client = $this->getClient();
        $refresh = $this->shouldRefresh($options);

        [$stale, $result, $record] = $this->performWrite(
            $client->updateSubrequest('write', $this->object, $identifierValue, $payload, $identifier),
            fn () => $client->upsert($this->object, $identifierValue, $payload, $identifier),
            $lookup,
            $refresh ? $lookup : null,
            $options
        );

        $this->invalidateAfterMutation($lookup, $stale, $payload);

        if (! $refresh) {
            return $this->localRecord($payload, array_merge($lookup, isset($result['id']) ? ['Id' => $result['id']] : []));
        }

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

    /**
     * Create many records in as few requests as possible using sObject Collections.
     *
     * Each record gets the same treatment as create(): schema defaults are merged in and null
     * defaults dropped. Records are sent in chunks of 200, one request per chunk, sequentially.
     * $allOrNone applies per request, so it does not give atomicity across chunks: a failure
     * in chunk two leaves chunk one committed and is raised as a SalesforceCollectionException
     * carrying the per-record errors.
     *
     * Unlike create() there is no read-back, which is the round trips this method exists to
     * remove. The return value is the list of SaveResults exactly as Salesforce returns them,
     * concatenated across chunks in request order, so results map to the input positionally.
     * Reach for create() instead when you need the stored record (server-computed fields,
     * includes) back.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array>
     */
    public function createMany(array $records, bool $allOrNone = true): array
    {
        $payloads = array_map(fn (array $record) => $this->batchPayload($record), array_values($records));

        return $this->sendBatches(
            $payloads,
            fn (array $chunk) => $this->getClient()->createMany($chunk, $allOrNone),
            fn (array $chunk) => $this->invalidateAfterBatch($chunk),
        );
    }

    /**
     * Upsert many records against a single identifier field in as few requests as possible,
     * using Salesforce's native upsert via sObject Collections.
     *
     * Like upsertByIdentifier(), the match and write are atomic per record and safe under
     * concurrency when the identifier is an External ID (or Id) field: simultaneous callers
     * converge on one record. Every record must carry a value for the identifier; a missing
     * value is rejected here because Salesforce would silently insert instead.
     *
     * Records are sent in chunks of 200, one request per chunk, sequentially. $allOrNone
     * applies per request, so it does not give atomicity across chunks. A failed record raises
     * a SalesforceCollectionException that names the identifier value of each failure.
     *
     * There is no read-back and no pre-write read of the current record: invalidation of the
     * entry cache uses the payload only, so an entry cached under an indexable field's previous
     * value survives until its TTL. Prefer upsertByIdentifier() when you need the stored record
     * back or indexable fields other than the identifier change on existing records.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @param  string|null  $identifier  Field to match on; defaults to the repository identifier.
     * @return array<int, array>  UpsertResults in request order
     */
    public function upsertManyByIdentifier(array $records, ?string $identifier = null, bool $allOrNone = true): array
    {
        $identifier ??= $this->defaultIdentifier;

        $payloads = [];

        foreach (array_values($records) as $index => $record) {
            $payload = $this->batchPayload($record);
            $value = $payload[$identifier] ?? null;

            if ($value === null || $value === '' || is_array($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Record %d has no value for the %s identifier; Salesforce would insert it instead of upserting.',
                    $index,
                    $identifier
                ));
            }

            $payloads[] = $payload;
        }

        return $this->sendBatches(
            $payloads,
            fn (array $chunk) => $this->getClient()->upsertMany($this->object, $identifier, $chunk, $allOrNone),
            fn (array $chunk) => $this->invalidateAfterBatch($chunk, [
                $identifier => array_column($chunk, $identifier),
            ]),
            fn (array $chunk) => array_column($chunk, $identifier),
        );
    }

    /**
     * Delete many records by identifier value in as few requests as possible using sObject
     * Collections. Ids are sent in chunks of 200, one request per chunk, sequentially;
     * $allOrNone applies per request, not across chunks.
     *
     * There is no pre-write read, so entries cached under indexable fields other than the
     * identifier survive until their TTL. Prefer delete() when that matters.
     *
     * @param  array<int, string>  $ids
     * @return array<int, array>  DeleteResults in request order
     */
    public function deleteMany(array $ids, bool $allOrNone = true): array
    {
        $ids = array_values(array_map('strval', $ids));

        return $this->sendBatches(
            $ids,
            fn (array $chunk) => $this->getClient()->deleteMany($chunk, $allOrNone),
            fn (array $chunk) => $this->invalidateAfterBatch([], [$this->defaultIdentifier => $chunk]),
            fn (array $chunk) => $chunk,
        );
    }

    public function delete(string $id): array
    {
        $lookup = [$this->defaultIdentifier => $id];
        $client = $this->getClient();

        [$stale, $result] = $this->performWrite(
            $client->deleteSubrequest('write', $this->object, $id, $this->defaultIdentifier),
            fn () => $client->delete($this->object, $id, $this->defaultIdentifier),
            $lookup,
            null,
            []
        );

        $this->invalidateAfterMutation($lookup, $stale);

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
            array_intersect_key($options, array_flip(['includes', 'with'])),
            ['select' => $this->singleRecordSelect($options), 'skip_retrieval' => true]
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
     * the lookup, selecting the repository's default fields unless the options narrow it.
     */
    protected function refreshQuery(array $lookup, array $options): Query
    {
        $query = $this->newQuery();

        foreach ($lookup as $field => $value) {
            $query->where($field, $value);
        }

        $refreshOptions = array_intersect_key($options, array_flip(['select', 'includes', 'with']));
        $refreshOptions['select'] = $this->singleRecordSelect($refreshOptions);

        return $this->applyOptions($query, $refreshOptions)->limit(1);
    }

    /**
     * Execute a write in a single HTTP request, together with whatever reads it needs:
     *
     *  - a stale read of the record's indexable fields before the write, so entries cached
     *    under the old values can be invalidated (only when the repository has indexable
     *    fields beyond its identifier and the record is not already in the entry cache);
     *  - a re-read of the record after the write, when a refresh is wanted.
     *
     * When neither read is needed the plain endpoint is used; otherwise the reads and the
     * write are sent as one composite request.
     *
     * @param  array  $writeSubrequest  composite subrequest for the write, referenceId "write"
     * @param  \Closure  $plainWrite  performs the same write against the plain endpoint
     * @param  array|null  $staleLookup  field => value locating the existing record, or null to skip
     * @param  array|null  $refreshLookup  field => value for the re-read, or null to skip
     * @return array{0: ?array, 1: array, 2: ?array}  [stale record, write response body, re-read record]
     */
    protected function performWrite(array $writeSubrequest, \Closure $plainWrite, ?array $staleLookup, ?array $refreshLookup, array $options): array
    {
        $client = $this->getClient();
        $stale = null;
        $subrequests = [];

        if ($staleLookup !== null && $staleLookup !== [] && $this->needsStaleCapture()) {
            $stale = $this->cachedRecord($staleLookup);

            if ($stale === null) {
                $subrequests[] = $client->querySubrequest('stale', $this->staleQuery($staleLookup)->toSoql());
            }
        }

        $subrequests[] = $writeSubrequest;

        if ($refreshLookup !== null) {
            $subrequests[] = $client->querySubrequest('refresh', $this->refreshQuery($refreshLookup, $options)->toSoql());
        }

        if (count($subrequests) === 1) {
            $result = $plainWrite();

            return [$stale, is_array($result) ? $result : [], null];
        }

        $results = $client->composite($subrequests);

        if ($stale === null && isset($results['stale'])) {
            $staleRecord = $results['stale']['body']['records'][0] ?? null;
            $stale = is_array($staleRecord) ? $staleRecord : null;
        }

        $write = is_array($results['write']['body'] ?? null) ? $results['write']['body'] : [];
        $record = $results['refresh']['body']['records'][0] ?? null;

        return [$stale, $write, is_array($record) ? $record : null];
    }

    /**
     * A record prepared for a collection request: defaults applied as create() applies them,
     * typed for this repository's object.
     */
    protected function batchPayload(array $record): array
    {
        $payload = array_replace_recursive($this->defaultValues, $record);
        $payload = $this->filterNullDefaults($payload, $record);

        $payload['attributes'] = ['type' => $this->object];

        return $payload;
    }

    /**
     * Send items in chunks of the collection limit, one request each, stopping at the first
     * chunk with a failed record. Successful chunks are invalidated before a later failure is
     * raised. Results are returned in request order.
     *
     * @param  \Closure(array): array  $send  performs the request for one chunk
     * @param  \Closure(array): void  $invalidate  invalidates the cache for one chunk
     * @param  (\Closure(array): array)|null  $identifiers  identifier value per item in a chunk
     * @return array<int, array>
     */
    protected function sendBatches(array $items, \Closure $send, \Closure $invalidate, ?\Closure $identifiers = null): array
    {
        $results = [];

        foreach (array_chunk($items, Salesforce::COLLECTION_LIMIT) as $chunk) {
            $chunkResults = array_values($send($chunk));

            $invalidate($chunk);

            foreach ($chunkResults as $result) {
                if (($result['success'] ?? false) !== true) {
                    throw SalesforceCollectionException::fromResults($chunkResults, $identifiers ? $identifiers($chunk) : []);
                }
            }

            $results = array_merge($results, $chunkResults);
        }

        return $results;
    }

    /**
     * Invalidate after a collection write: one namespace rotation for the object and one
     * token rotation covering every identifier and indexable value in the payloads. Payload
     * values only; there is no stale read in the batch paths.
     *
     * @param  array<int, array>  $payloads
     * @param  array<string, array<int, mixed>>  $identities  field => values known outside the payloads
     */
    protected function invalidateAfterBatch(array $payloads, array $identities = []): void
    {
        if (! $this->cacheHandler) {
            return;
        }

        $this->cacheHandler->flushQueryCacheForObject($this->object);

        $conditions = [];

        foreach ($identities as $field => $values) {
            foreach ($values as $value) {
                if ($value !== null && ! is_array($value)) {
                    $conditions[$field][] = $value;
                }
            }
        }

        foreach ($payloads as $payload) {
            foreach ($this->indexableFields as $field) {
                $value = $payload[$field] ?? null;

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

    /**
     * Whether writes need the record's current values to invalidate entries cached under
     * indexable fields other than the identifier.
     */
    protected function needsStaleCapture(): bool
    {
        return $this->cacheHandler !== null && $this->hasNonDefaultIndexableFields();
    }

    /**
     * The record a default find() for this lookup would return, from the entry cache only.
     */
    protected function cachedRecord(array $lookup): ?array
    {
        if (! $this->cacheHandler) {
            return null;
        }

        $cached = $this->cacheHandler->getEntry($this->refreshQuery($lookup, []));

        return is_array($cached) ? $cached : null;
    }

    /**
     * The pre-write read: only the indexable fields, since that is all invalidation needs.
     */
    protected function staleQuery(array $lookup): Query
    {
        $query = $this->newQuery();

        foreach ($lookup as $field => $value) {
            $query->where($field, $value);
        }

        return $query->select($this->indexableFields)->limit(1);
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

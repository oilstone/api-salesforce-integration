<?php

namespace Oilstone\ApiSalesforceIntegration;

use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Exceptions\RecordNotFoundException;

class Repository
{
    protected array $indexableFields;

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
            throw new RecordNotFoundException();
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
            throw new RecordNotFoundException();
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

    public function create(array $attributes): array
    {
        $payload = array_replace_recursive($this->defaultValues, $attributes);
        $payload = $this->filterNullDefaults($payload, $attributes);

        $result = $this->getClient()->create($this->object, $payload);

        if ($this->cacheHandler) {
            $this->cacheHandler->flushQueryCache();
        }

        $recordId = $result['id'] ?? null;

        if ($recordId === null) {
            return array_merge($payload, $result ?? []);
        }

        return $this->findOrFail([$this->defaultIdentifier => $recordId], ['skip_retrieval' => true]);
    }

    public function update(string $id, array $attributes): array
    {
        $payload = array_replace_recursive($this->defaultValues, $attributes);
        $payload = $this->filterNullDefaults($payload, $attributes);

        $stale = $this->captureStaleForInvalidation([$this->defaultIdentifier => $id]);

        $this->getClient()->update($this->object, $id, $payload, $this->defaultIdentifier);

        $this->invalidateAfterMutation($id, $stale, $payload);

        return $this->findOrFail([$this->defaultIdentifier => $id], ['skip_retrieval' => true]);
    }

    public function upsertRecord(string $identifierValue, array $attributes, ?string $identifier = null): array
    {
        $payload = array_replace_recursive($this->defaultValues, $attributes);
        $payload = $this->filterNullDefaults($payload, $attributes);

        $identifier ??= $this->defaultIdentifier;

        $stale = $this->captureStaleForInvalidation([$identifier => $identifierValue]);

        $this->getClient()->upsert($this->object, $identifierValue, $payload, $identifier);

        if ($this->cacheHandler) {
            $this->cacheHandler->flushQueryCache();
            $this->cacheHandler->forgetEntryByConditions($this->object, [$identifier => $identifierValue]);

            if (is_array($stale)) {
                $this->forgetIndexableFields($stale, $payload);
            } else {
                $this->forgetPayloadIndexableFields($payload);
            }
        }

        return $this->findOrFail([$identifier => $identifierValue], ['skip_retrieval' => true]);
    }

    public function upsert(array $conditions, array $attributes): array
    {
        $existing = $this->first($conditions, ['skip_retrieval' => true]);

        if ($existing) {
            return $this->update($existing[$this->defaultIdentifier], $attributes);
        }

        return $this->create(array_replace_recursive($attributes, $conditions));
    }

    public function delete(string $id): array
    {
        $stale = $this->captureStaleForInvalidation([$this->defaultIdentifier => $id]);

        $result = $this->getClient()->delete($this->object, $id, $this->defaultIdentifier);

        if ($this->cacheHandler) {
            $this->cacheHandler->flushQueryCache();
            $this->cacheHandler->forgetEntryByConditions($this->object, [$this->defaultIdentifier => $id]);

            if (is_array($stale)) {
                $this->forgetIndexableFields($stale);
            }
        }

        return $result;
    }

    public function getById(string $id, array $options = []): ?array
    {
        return $this->find($id, $options);
    }

    public function firstOrCreate(array $attributes, array $extra = []): array
    {
        $query = $this->newQuery();

        foreach ($attributes as $field => $value) {
            $query->where($field, $value);
        }

        $record = $this->applyOptions($query, [
            'select' => ['FIELDS(ALL)'],
            'skip_retrieval' => true,
        ])->first();

        if ($record) {
            return $record;
        }

        return $this->create(array_merge($attributes, $extra));
    }

    public function updateOrCreate(array $attributes, array $values = []): array
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
            return $this->update($record[$this->defaultIdentifier], $values);
        }

        return $this->create(array_merge($attributes, $values));
    }

    protected function isOptionsArray(array $data): bool
    {
        $optionKeys = ['conditions', 'select', 'includes', 'with', 'order', 'sort', 'limit', 'offset', 'skip_retrieval'];

        return (bool) array_intersect(array_keys($data), $optionKeys);
    }

    protected function getClient(): Salesforce
    {
        return $this->client ?? app(Salesforce::class);
    }

    protected function captureStaleForInvalidation(array $lookup): ?array
    {
        if (! $this->cacheHandler || ! $this->hasNonDefaultIndexableFields()) {
            return null;
        }

        return $this->first($lookup);
    }

    protected function invalidateAfterMutation(string $id, ?array $stale, array $payload): void
    {
        if (! $this->cacheHandler) {
            return;
        }

        $this->cacheHandler->flushQueryCache();
        $this->cacheHandler->forgetEntryByConditions($this->object, [$this->defaultIdentifier => $id]);

        if (is_array($stale)) {
            $this->forgetIndexableFields($stale, $payload);
        } else {
            $this->forgetPayloadIndexableFields($payload);
        }
    }

    protected function forgetIndexableFields(array $record, array $payload = []): void
    {
        if (! $this->cacheHandler) {
            return;
        }

        foreach ($this->indexableFields as $field) {
            if ($field === $this->defaultIdentifier) {
                continue;
            }

            $oldValue = $record[$field] ?? null;
            $newValue = $payload[$field] ?? null;

            foreach (array_unique(array_filter([$oldValue, $newValue], static fn ($v) => $v !== null), SORT_REGULAR) as $value) {
                $this->cacheHandler->forgetEntryByConditions($this->object, [$field => $value]);
            }
        }
    }

    protected function forgetPayloadIndexableFields(array $payload): void
    {
        if (! $this->cacheHandler) {
            return;
        }

        foreach ($this->indexableFields as $field) {
            if ($field === $this->defaultIdentifier) {
                continue;
            }

            $value = $payload[$field] ?? null;

            if ($value === null) {
                continue;
            }

            $this->cacheHandler->forgetEntryByConditions($this->object, [$field => $value]);
        }
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

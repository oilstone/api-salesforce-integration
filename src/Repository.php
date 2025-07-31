<?php

namespace Oilstone\ApiSalesforceIntegration;

use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Exceptions\RecordNotFoundException;

class Repository
{
    public function __construct(
        protected string $object,
        protected array $defaultConstraints = [],
        protected array $defaultIncludes = [],
        protected array $defaultValues = [],
        protected string $defaultIdentifier = 'Id',
        protected ?QueryCacheHandler $cacheHandler = null,
        protected ?Salesforce $client = null,
    ) {}

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
        $this->defaultIdentifier = $identifier;

        return $this;
    }

    /**
     * Set default attribute values.
     */
    public function setDefaultValues(array $values): static
    {
        $this->defaultValues = $values;

        return $this;
    }

    /**
     * Retrieve the default attribute values.
     */
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

    public function newQuery(?string $object = null): Query
    {
        $query = new Query($object ?? $this->object, $this->getClient(), $this->defaultIdentifier);

        if ($this->cacheHandler) {
            $query->setCacheHandler($this->cacheHandler);
            $query->setCacheTags([$object ?? $this->object]);
        }

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

        return $query;
    }

    public function find(string|array $idConditionsOrOptions, array $options = []): ?Record
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

            if ($this->cacheHandler) {
                $query->setCacheTags([$this->object, $this->object.':'.$id]);
            }
        }

        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $this->applyOptions($query, $options)->first();
    }

    public function findOrFail(string|array $idConditionsOrOptions, array $options = []): Record
    {
        $record = $this->find($idConditionsOrOptions, $options);

        if (! $record) {
            throw new RecordNotFoundException();
        }

        return $record;
    }

    public function first(array $conditionsOrOptions = [], array $options = []): ?Record
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

    public function firstOrFail(array $conditionsOrOptions = [], array $options = []): Record
    {
        $record = $this->first($conditionsOrOptions, $options);

        if (! $record) {
            throw new RecordNotFoundException();
        }

        return $record;
    }

    public function get(array $conditionsOrOptions = [], array $options = []): Collection
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

    /**
     * Count the number of records that match the given conditions.
     */
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

        foreach ($options['conditions'] ?? [] as $condition) {
            if (is_callable($condition)) {
                $condition($query);
                continue;
            }

            if (is_array($condition)) {
                $query->where(...$condition);
            }
        }

        return $query->count();
    }

    public function create(array $attributes): array
    {
        $payload = array_replace_recursive($this->defaultValues, $attributes);
        $payload = $this->filterNullDefaults($payload, $attributes);

        $result = $this->getClient()->create($this->object, $payload);

        if ($this->cacheHandler) {
            $this->cacheHandler->flush([$this->object]);
        }

        return array_merge($payload, $result ?? []);
    }

    public function update(string $id, array $attributes): array
    {
        $payload = array_replace_recursive($this->defaultValues, $attributes);
        $payload = $this->filterNullDefaults($payload, $attributes);

        $result = $this->getClient()->update($this->object, $id, $payload);

        if ($this->cacheHandler) {
            $this->cacheHandler->flush([$this->object.':'.$id]);
        }

        return array_merge($payload, [$this->defaultIdentifier => $id], $result ?? []);
    }

    public function delete(string $id): array
    {
        $result = $this->getClient()->delete($this->object, $id);

        if ($this->cacheHandler) {
            $this->cacheHandler->flush([$this->object.':'.$id]);
        }

        return $result;
    }

    public function getById(string $id, array $options = []): ?Record
    {
        return $this->find($id, $options);
    }

    public function firstOrCreate(array $attributes, array $extra = []): Record
    {
        $query = $this->newQuery();

        foreach ($attributes as $field => $value) {
            $query->where($field, $value);
        }

        $record = $this->applyOptions($query, ['select' => ['FIELDS(ALL)']])->first();

        if ($record) {
            return $record;
        }

        $result = $this->create(array_merge($attributes, $extra));

        return $this->findOrFail($result['id']);
    }

    public function updateOrCreate(array $attributes, array $values = []): Record
    {
        $query = $this->newQuery();

        foreach ($attributes as $field => $value) {
            $query->where($field, $value);
        }

        $record = $this->applyOptions($query, ['select' => [$this->defaultIdentifier]])->first();

        if ($record) {
            $this->update($record[$this->defaultIdentifier], $values);
            return $this->findOrFail($record[$this->defaultIdentifier]);
        }

        $result = $this->create(array_merge($attributes, $values));

        return $this->findOrFail($result['id']);
    }

    protected function isOptionsArray(array $data): bool
    {
        $optionKeys = ['conditions', 'select', 'includes', 'with', 'order', 'sort', 'limit', 'offset'];

        return (bool) array_intersect(array_keys($data), $optionKeys);
    }

    protected function getClient(): Salesforce
    {
        return $this->client ?? app(Salesforce::class);
    }

    /**
     * Remove null values that were not provided in the attributes array.
     */
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

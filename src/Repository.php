<?php

namespace Oilstone\ApiSalesforceIntegration;

use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

class Repository
{
    public function __construct(
        protected string $object,
        protected array $defaultConstraints = [],
        protected array $defaultIncludes = [],
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
        $query = new Query($object ?? $this->object, $this->getClient());

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
            $query->where('Id', $id);

            if ($this->cacheHandler) {
                $query->setCacheTags([$this->object, $this->object.':'.$id]);
            }
        }

        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $this->applyOptions($query, $options)->first();
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

    public function get(array $conditionsOrOptions = [], array $options = []): Collection
    {
        if ($options === [] && $this->isOptionsArray($conditionsOrOptions)) {
            $options = $conditionsOrOptions;
            $conditions = [];
        } else {
            $conditions = $conditionsOrOptions;
        }

        $options['select'] = $options['select'] ?? ['Id'];

        $query = $this->newQuery();

        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $this->applyOptions($query, $options)->get();
    }

    public function create(array $attributes): array
    {
        return $this->getClient()->create($this->object, $attributes);
    }

    public function update(string $id, array $attributes): array
    {
        $result = $this->getClient()->update($this->object, $id, $attributes);

        if ($this->cacheHandler) {
            $this->cacheHandler->flush([$this->object.':'.$id]);
        }

        return $result;
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

        return $this->find($result['id']);
    }

    public function updateOrCreate(array $attributes, array $values = []): Record
    {
        $query = $this->newQuery();

        foreach ($attributes as $field => $value) {
            $query->where($field, $value);
        }

        $record = $this->applyOptions($query, ['select' => ['Id']])->first();

        if ($record) {
            $this->update($record['Id'], $values);
            return $this->find($record['Id']);
        }

        $result = $this->create(array_merge($attributes, $values));

        return $this->find($result['id']);
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
}

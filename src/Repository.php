<?php

namespace Oilstone\ApiSalesforceIntegration;

use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

class Repository
{
    public function __construct(
        protected string $object,
        protected array $defaultConstraints = [],
        protected array $defaultIncludes = [],
        protected ?\Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler $cacheHandler = null,
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

    public function setCacheHandler(\Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler $handler): static
    {
        $this->cacheHandler = $handler;

        return $this;
    }

    public function newQuery(?string $object = null): Query
    {
        $query = new Query($object ?? $this->object, $this->getClient());

        if ($this->cacheHandler) {
            $query->setCacheHandler($this->cacheHandler);
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

    public function find(string $id, array $options = []): ?Record
    {
        $options['select'] = $options['select'] ?? ['FIELDS(ALL)'];

        return $this->applyOptions($this->newQuery()->where('Id', $id), $options)->first();
    }

    public function first(array $options = []): ?Record
    {
        $options['select'] = $options['select'] ?? ['FIELDS(ALL)'];

        return $this->applyOptions($this->newQuery(), $options)->first();
    }

    public function get(array $options = []): Collection
    {
        $options['select'] = $options['select'] ?? ['Id'];

        return $this->applyOptions($this->newQuery(), $options)->get();
    }

    public function create(array $attributes): array
    {
        return $this->getClient()->create($this->object, $attributes);
    }

    public function update(string $id, array $attributes): array
    {
        return $this->getClient()->update($this->object, $id, $attributes);
    }

    public function delete(string $id): array
    {
        return $this->getClient()->delete($this->object, $id);
    }

    protected function getClient(): Salesforce
    {
        return app(Salesforce::class);
    }
}

<?php

namespace Oilstone\ApiSalesforceIntegration;

use Aggregate\Map;
use Api\Result\Contracts\Record as ApiRecordContract;
use Oilstone\ApiSalesforceIntegration\Collection;

class Record extends Map implements ApiRecordContract
{
    protected iterable $meta = [];

    public static function make(array $item): static
    {
        return (new static)->fill($item);
    }

    public function fill(array $attributes): static
    {
        if (array_key_exists('attributes', $attributes)) {
            $this->meta = $attributes['attributes'];
            unset($attributes['attributes']);
        }

        return parent::fill($attributes);
    }

    public function getRelations(): array
    {
        $relations = [];

        foreach ($this->extractRelations() as $relation => $data) {
            $relations[Query::resolveRelation($relation) ?? $relation] = $data;
        }

        return $relations;
    }

    public function getAttributes(): array
    {
        return $this->all();
    }

    public function getMetaData(): iterable
    {
        return $this->meta;
    }

    public function setMetaData(iterable $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->get($key);
    }

    protected function extractRelations(): array
    {
        return array_filter(array_map(function (mixed $property) {
            if (! is_array($property)) {
                return null;
            }

            if (isset($property[0])) {
                return Collection::make($property);
            }

            return (new static)->fill($property);
        }, $this->all()));
    }
}

<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Results;

use Aggregate\Map;
use Api\Result\Contracts\Record as ApiRecordContract;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Results\Collection;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

class Record extends Map implements ApiRecordContract
{
    protected iterable $meta = [];


    protected static ?Salesforce $client = null;

    public static function setClient(Salesforce $client): void
    {
        self::$client = $client;
    }

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

        foreach ($attributes as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (array_key_exists('records', $value) && is_array($value['records'])) {
                $mapped = array_values(array_map(function ($record) {
                    if (!is_array($record)) {
                        return $record;
                    }

                    if (array_key_exists('attributes', $record)) {
                        unset($record['attributes']);
                    }

                    $keys = array_keys($record);
                    if (count($keys) === 1 && str_ends_with($keys[0], '__r') && is_array($record[$keys[0]])) {
                        $record = $record[$keys[0]];

                        if (array_key_exists('attributes', $record)) {
                            unset($record['attributes']);
                        }
                    }

                    return $record;
                }, $value['records']));

                $objectName = $this->relationshipObjectName($key) ?? $key;
                $attributes[$objectName] = $mapped;

                if ($objectName !== $key) {
                    unset($attributes[$key]);
                }

                continue;
            }
        }

        return parent::fill($attributes);
    }

    public function getRelations(): array
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

    protected function relationshipObjectName(string $relation): ?string
    {
        $object = $this->meta['type'] ?? null;

        if (! $object) {
            return null;
        }

        $description = $this->describe($object);

        foreach ($description['childRelationships'] ?? [] as $relationship) {
            if (strcasecmp($relationship['relationshipName'] ?? '', $relation) === 0) {
                return $relationship['childSObject'] ?? null;
            }
        }

        return null;
    }

    protected function describe(string $object): array
    {
        $client = self::$client ?? app(Salesforce::class);

        return $client->describe($object);
    }
}

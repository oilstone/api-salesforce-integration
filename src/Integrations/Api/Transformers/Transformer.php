<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Transformers;

use Api\Result\Contracts\Record;
use Api\Schema\Schema;
use Api\Schema\Property as SchemaProperty;
use Api\Transformers\Contracts\Transformer as Contract;
use Carbon\Carbon;

class Transformer implements Contract
{
    public function __construct(
        protected Schema $schema
    ) {}

    public function transform(Record $record): array
    {
        return $this->transformSchema($this->schema, $record->getAttributes());
    }

    public function reverse(array $attributes): array
    {
        return $this->reverseSchema($this->schema, $attributes);
    }

    public function transformMetaData(Record $record): array
    {
        if (method_exists($record, 'getMetaData')) {
            $meta = $record->getMetaData();

            return is_array($meta) ? $meta : iterator_to_array($meta);
        }

        return [];
    }

    protected function transformSchema(Schema $schema, array $attributes): array
    {
        $transformed = [];

        foreach ($schema->getProperties() as $property) {
            if ($property->hasMeta('validationOnly')) {
                continue;
            }

            if ($property->getAccepts() instanceof Schema && $property->getType() !== 'collection') {
                $transformed[$property->getName()] = $this->transformSchema($property->getAccepts(), $attributes);

                continue;
            }

            $key = $property->alias ?: $property->getName();
            $path = explode('.', $key);
            $currentAttributes = $attributes;

            while (count($path) > 1) {
                $currentAttributes = $currentAttributes[array_shift($path)] ?? [];
            }

            if (is_object($currentAttributes) && method_exists($currentAttributes, 'jsonSerialize')) {
                $currentAttributes = $currentAttributes->jsonSerialize();
            }

            $value = $currentAttributes[$path[0]] ?? null;

            if ($value) {
                switch ($property->getType()) {
                    case 'date':
                        $value = Carbon::parse($value)->toDateString();
                        break;

                    case 'datetime':
                    case 'timestamp':
                        $value = Carbon::parse($value)->toDateTimeString();
                        break;

                    case 'collection':
                        $value = array_values(array_filter(array_map(function ($item) use ($property) {
                            return $item ? $this->transformSchema($property->getAccepts(), $item) : null;
                        }, $value)));
                        break;
                }
            }

            if ($property->hasMeta('isYesNo')) {
                $value = $value !== null ? strtolower((string) $value) === 'yes' : null;
            }

            if ($property->hasMeta('isAddressLine')) {
                $line = (int) $property->isAddressLine;
                $lines = preg_split('/\r\n|\n|\r/', (string) $value);

                $transformed[$property->getName()] = ($lines[$line - 1] ?? null) ?: null;

                continue;
            }

            $transformed[$property->getName()] = $value;
        }

        return $transformed;
    }

    protected function reverseSchema(Schema $schema, array $attributes): array
    {
        $reversed = [];
        $addressLines = [];

        foreach ($schema->getProperties() as $property) {
            if ($property->hasMeta('readonly') || $property->hasMeta('validationOnly')) {
                continue;
            }

            if ($property->getAccepts() instanceof Schema && $property->getType() !== 'collection') {
                $value = $attributes[$property->getName()] ?? [];

                if (is_array($value)) {
                    $reversed = array_replace_recursive($reversed, $this->reverseSchema($property->getAccepts(), $value));
                }

                continue;
            }

            $key = $property->alias ?: $property->getName();

            if ($property->hasMeta('isAddressLine')) {
                $line = (int) $property->isAddressLine;
                $lineValue = $attributes[$property->getName()] ?? null;

                if ($lineValue === null && array_key_exists($key, $attributes)) {
                    $lines = preg_split('/\r\n|\n|\r/', (string) $attributes[$key]);
                    $lineValue = $lines[$line - 1] ?? null;
                }

                if (! isset($addressLines[$key])) {
                    $addressLines[$key] = [];
                }

                $addressLines[$key][$line] = $lineValue;

                continue;
            }

            $value = $attributes[$property->getName()] ?? null;

            if ($property->hasMeta('isYesNo') && $value !== null) {
                $value = $value ? 'Yes' : 'No';
            }

            if ($value) {
                switch ($property->getType()) {
                    case 'date':
                        $value = Carbon::parse($value)->toDateString();
                        break;

                    case 'datetime':
                    case 'timestamp':
                        $value = Carbon::parse($value)->toDateTimeString();
                        break;

                    case 'collection':
                        $value = array_values(array_filter(array_map(function ($item) use ($property) {
                            return $item ? $this->reverseSchema($property->getAccepts(), $item) : null;
                        }, $value)));
                        break;
                }
            }

            $path = explode('.', $key);
            $current = &$reversed;

            while (count($path) > 1) {
                $segment = array_shift($path);

                if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                    $current[$segment] = [];
                }

                $current = &$current[$segment];
            }

            $current[$path[0]] = $value;
        }

        foreach ($addressLines as $alias => $lines) {
            if (array_key_exists($alias, $attributes) && is_string($attributes[$alias])) {
                $value = $attributes[$alias];
            } else {
                ksort($lines);
                $value = trim(implode("\n", array_filter($lines, fn($line) => $line !== null && $line !== ''))) ?: null;
            }

            $path = explode('.', $alias);
            $current = &$reversed;

            while (count($path) > 1) {
                $segment = array_shift($path);

                if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                    $current[$segment] = [];
                }

                $current = &$current[$segment];
            }

            $current[$path[0]] = $value;
        }

        return $reversed;
    }
}

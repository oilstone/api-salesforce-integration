<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Transformers;

use Api\Result\Contracts\Record;
use Api\Schema\Schema;
use Api\Transformers\Contracts\Transformer as Contract;
use ArgumentCountError;
use Carbon\Carbon;
use TypeError;

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

    public function forceReverse(array $attributes): array
    {
        return $this->reverseSchema($this->schema, $attributes, true);
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

            if ($property->hasMeta('beforeTransform') && is_callable($property->beforeTransform)) {
                $value = ($property->beforeTransform)($value, $attributes);
            }

            if ($property->hasMeta('fixed')) {
                $value = $this->resolvePropertyValue($property->fixed, $property, $attributes);
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
                            return $item ? $this->transformSchema($property->getAccepts(), $item) : null;
                        }, $value)));
                        break;
                }
            }

            if ($property->hasMeta('delimited') && $value !== null && ! is_array($value)) {
                $value = $value === '' ? [] : explode($property->delimited, (string) $value);
            }

            if ($property->hasMeta('isYesNo')) {
                $value = $value !== null ? strtolower((string) $value) === 'yes' : null;
            }

            if ($property->hasMeta('isAddressLine')) {
                $line = (int) $property->isAddressLine;
                $lines = preg_split('/\r\n|\n|\r/', (string) $value);

                $transformed[$property->getName()] = ($lines[$line - 1] ?? null) ?: null;

                if ($property->hasMeta('afterTransform') && is_callable($property->afterTransform)) {
                    $transformed[$property->getName()] = ($property->afterTransform)($transformed[$property->getName()], $attributes);
                }

                continue;
            }

            if ($property->hasMeta('afterTransform') && is_callable($property->afterTransform)) {
                $value = ($property->afterTransform)($value, $attributes);
            }

            $transformed[$property->getName()] = $value;
        }

        return $transformed;
    }

    protected function reverseSchema(Schema $schema, array $attributes, bool $force = false): array
    {
        $reversed = [];
        $addressLines = [];

        foreach ($schema->getProperties() as $property) {
            if (
                $property->hasMeta('validationOnly') ||
                $property->hasMeta('isRelation') ||
                $property->hasMeta('calculated') ||
                (!$force && $property->hasMeta('readonly'))
            ) {
                continue;
            }

            if ($property->getAccepts() instanceof Schema && $property->getType() !== 'collection') {
                $key = $property->getName();

                if (! array_key_exists($key, $attributes) && ! $force && ! $property->hasMeta('fixed') && ! $property->hasMeta('default')) {
                    continue;
                }

                $value = $attributes[$key] ?? [];

                if (is_array($value)) {
                    $nested = $this->reverseSchema($property->getAccepts(), $value, $force);

                    if ($nested !== []) {
                        $reversed = array_replace_recursive($reversed, $nested);
                    }
                }

                continue;
            }

            $key = $property->alias ?: $property->getName();

            $hasValue = array_key_exists($property->getName(), $attributes) || array_key_exists($key, $attributes);

            if (! $hasValue && ! $force && ! $property->hasMeta('fixed') && ! $property->hasMeta('default')) {
                continue;
            }

            if ($property->hasMeta('isAddressLine')) {
                $line = (int) $property->isAddressLine;
                $lineValue = $attributes[$property->getName()] ?? null;

                if ($property->hasMeta('beforeReverse') && is_callable($property->beforeReverse)) {
                    $lineValue = ($property->beforeReverse)($lineValue, $attributes);
                }

                if ($lineValue === null && array_key_exists($key, $attributes)) {
                    $lines = preg_split('/\r\n|\n|\r/', (string) $attributes[$key]);
                    $lineValue = $lines[$line - 1] ?? null;
                }

                if ($property->hasMeta('fixed') && !($force && $hasValue)) {
                    $lineValue = $this->resolvePropertyValue($property->fixed, $property, $attributes);
                } elseif ($lineValue === null && $property->hasMeta('default')) {
                    $lineValue = $this->resolvePropertyValue($property->default, $property, $attributes);
                }

                if ($property->hasMeta('afterReverse') && is_callable($property->afterReverse)) {
                    $lineValue = ($property->afterReverse)($lineValue, $attributes);
                }

                if (! isset($addressLines[$key])) {
                    $addressLines[$key] = [];
                }

                $addressLines[$key][$line] = $lineValue;

                continue;
            }

            $value = $attributes[$property->getName()] ?? null;

            if ($property->hasMeta('beforeReverse') && is_callable($property->beforeReverse)) {
                $value = ($property->beforeReverse)($value, $attributes);
            }

            if ($property->hasMeta('fixed') && !($force && $hasValue)) {
                $value = $this->resolvePropertyValue($property->fixed, $property, $attributes);
            } elseif ($value === null && $property->hasMeta('default')) {
                $value = $this->resolvePropertyValue($property->default, $property, $attributes);
            }

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
                        $value = array_values(array_filter(array_map(function ($item) use ($property, $force) {
                            return $item ? $this->reverseSchema($property->getAccepts(), $item, $force) : null;
                        }, $value)));
                        break;
                }
            }

            if ($property->hasMeta('delimited') && is_array($value)) {
                $value = implode($property->delimited, $value);
            }

            if ($property->hasMeta('afterReverse') && is_callable($property->afterReverse)) {
                $value = ($property->afterReverse)($value, $attributes);
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

    protected function resolvePropertyValue(mixed $value, $property, array $attributes = []): mixed
    {
        if (! is_callable($value)) {
            return $value;
        }

        $attempts = [
            fn () => $value($attributes, $property),
            fn () => $value($attributes),
            fn () => $value($property),
            fn () => $value(),
        ];

        foreach ($attempts as $attempt) {
            try {
                return $attempt();
            } catch (ArgumentCountError|TypeError) {
                // Try the next argument combination until one matches.
            }
        }

        return $value;
    }
}

<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Transformers;

use Api\Result\Contracts\Record;
use Api\Schema\Schema;
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
                $lines = preg_split('/\r\n|\n|\r/', (string) $value);

                for ($i = 1; $i <= 3; $i++) {
                    $transformed[$property->getName() . $i] = $lines[$i - 1] ?? null;
                }

                continue;
            }

            $transformed[$property->getName()] = $value;
        }

        return $transformed;
    }

    protected function reverseSchema(Schema $schema, array $attributes): array
    {
        $reversed = [];

        foreach ($schema->getProperties() as $property) {
            if ($property->hasMeta('readonly')) {
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
                $lines = [];

                for ($i = 1; $i <= 3; $i++) {
                    $lineKey = $property->getName() . $i;

                    if (array_key_exists($lineKey, $attributes)) {
                        $lines[] = $attributes[$lineKey];
                    }
                }

                if (empty($lines) && array_key_exists($property->getName(), $attributes)) {
                    $lines = preg_split('/\r\n|\n|\r/', (string) $attributes[$property->getName()]);
                }

                $value = implode("\n", array_filter($lines, fn($line) => $line !== null && $line !== ''));
            } else {
                $value = $attributes[$property->getName()] ?? null;
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

        return $reversed;
    }
}

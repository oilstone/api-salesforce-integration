<?php

namespace Oilstone\ApiSalesforceIntegration;

use Aggregate\Set;

class RecordCollection extends Set
{
    public static function make(array $records): static
    {
        return (new static)->fill($records);
    }

    public function toArray(): array
    {
        return array_map(fn (Record $record) => $record->toArray(), $this->all());
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }
}

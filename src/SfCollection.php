<?php

namespace Oilstone\ApiSalesforceIntegration;

use Aggregate\Set;

class SfCollection extends Set
{
    public static function make(array $records): static
    {
        return (new static)->fill($records);
    }

    public function toArray(): array
    {
        return array_map(fn (SfRecord $record) => $record->toArray(), $this->all());
    }
}

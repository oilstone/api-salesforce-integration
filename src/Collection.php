<?php

namespace Oilstone\ApiSalesforceIntegration;

use Aggregate\Set;
use Api\Result\Contracts\Collection as Contract;
use Api\Result\Contracts\Record as ResultRecordInterface;

class Collection extends Set implements Contract
{
    protected iterable $meta = [];

    public static function make(array $items, iterable $meta = []): static
    {
        return (new static)->setMetaData($meta)->fill(array_map(fn ($item) => $item instanceof ResultRecordInterface ? $item : Record::make($item), $items));
    }

    public function getItems(): iterable
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
}

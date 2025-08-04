<?php

namespace Oilstone\ApiSalesforceIntegration;

use Aggregate\Map;

class Record extends Map
{
    protected array $raw = [];

    public static function make(array $attributes, array $raw = []): static
    {
        return (new static)->fill($attributes)->setRawAttributes($raw);
    }

    public function setRawAttributes(?array $raw): static
    {
        $this->raw = $raw ?? [];

        return $this;
    }

    public function getAttributes(): array
    {
        return $this->all();
    }

    public function getAttribute(string $key): mixed
    {
        return $this->get($key);
    }

    public function toArray(): array
    {
        return $this->all();
    }

    public function getRawAttributes(): array
    {
        return $this->raw;
    }

    public function getRawAttribute(string $key): mixed
    {
        return $this->raw[$key] ?? null;
    }
}

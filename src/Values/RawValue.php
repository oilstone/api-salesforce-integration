<?php

namespace Oilstone\ApiSalesforceIntegration\Values;

/**
 * A literal that should be written into SOQL verbatim, without quoting.
 *
 * Use this for date and datetime literals (which SOQL rejects when quoted) and for
 * SOQL date functions such as TODAY or LAST_N_DAYS:7.
 */
final class RawValue implements \Stringable
{
    public function __construct(protected string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

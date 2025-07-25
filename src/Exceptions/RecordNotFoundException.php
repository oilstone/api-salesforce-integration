<?php

namespace Oilstone\ApiSalesforceIntegration\Exceptions;

class RecordNotFoundException extends Exception
{
    public function __construct()
    {
        parent::__construct('Record not found', 404);
    }
}

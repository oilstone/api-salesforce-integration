<?php

namespace Oilstone\ApiSalesforceIntegration\Exceptions;

class ObjectNotSpecifiedException extends Exception
{
    public function __construct()
    {
        parent::__construct('No Salesforce object specified', 500);
    }
}

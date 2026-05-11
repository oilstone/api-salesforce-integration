<?php

namespace Oilstone\ApiSalesforceIntegration\Auth;

interface TokenManager
{
    public function getToken(): string;

    public function refresh(): string;
}

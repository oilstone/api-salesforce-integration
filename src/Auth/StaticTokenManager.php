<?php

namespace Oilstone\ApiSalesforceIntegration\Auth;

class StaticTokenManager implements TokenManager
{
    public function __construct(protected string $token) {}

    public function getToken(): string
    {
        return $this->token;
    }

    public function refresh(): string
    {
        return $this->token;
    }
}

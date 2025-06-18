<?php

namespace Oilstone\ApiSalesforceIntegration\Exceptions;

use Psr\Http\Message\ResponseInterface;

class SalesforceException extends Exception
{
    protected array $errors = [];

    public function __construct(array $errors, int $code)
    {
        parent::__construct($errors[0]['message'] ?? 'Salesforce error', $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $data = json_decode((string) $response->getBody(), true) ?: [];
        $errors = isset($data[0]) ? $data : [$data];

        return new self($errors, $response->getStatusCode());
    }
}

<?php

namespace Oilstone\ApiSalesforceIntegration\Exceptions;

use Psr\Http\Message\ResponseInterface;

class SalesforceException extends Exception
{
    protected array $errors = [];

    public function __construct(array $errors, int $code)
    {
        $first = $errors[0] ?? [];

        $message = $first['message']
            ?? $first['error_description']
            ?? $first['error']
            ?? 'Salesforce error';

        parent::__construct((string) $message, $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (is_array($data) && $data !== []) {
            $errors = array_is_list($data) ? $data : [$data];
        } else {
            // Non-JSON bodies (HTML error pages, proxies, empty 5xx) would otherwise
            // surface as a bare "Salesforce error" with no detail.
            $summary = trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]*>/', ' ', $body) ?? '') ?? '');
            $message = sprintf('HTTP %d %s', $status, $response->getReasonPhrase());

            if ($summary !== '') {
                $message .= ': '.mb_substr($summary, 0, 200);
            }

            $errors = [['message' => $message, 'errorCode' => 'HTTP_'.$status]];
        }

        return new self($errors, $status);
    }
}

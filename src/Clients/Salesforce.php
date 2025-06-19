<?php

namespace Oilstone\ApiSalesforceIntegration\Clients;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Oilstone\ApiSalesforceIntegration\Exceptions\SalesforceException;

class Salesforce
{
    protected Client $httpClient;

    protected string $instanceUrl;

    protected string $accessToken;

    protected string $instanceVersion;

    protected ?LoggerInterface $logger = null;

    public function __construct(Client $httpClient, string $instanceUrl, string $accessToken, string $instanceVersion = 'v52.0', ?LoggerInterface $logger = null)
    {
        $this->httpClient = $httpClient;
        $this->instanceUrl = rtrim($instanceUrl, '/');
        $this->accessToken = $accessToken;
        $this->instanceVersion = $instanceVersion;
        $this->logger = $logger;
    }

    protected function log(string $method, string $url, array $context, array $response, int $status): void
    {
        if (! $this->logger) {
            return;
        }

        $this->logger->debug('Salesforce request', array_merge($context, [
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'response' => $response,
        ]));
    }

    protected function request(string $method, string $url, array $options): array
    {
        $response = $this->httpClient->request($method, $url, $options);

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->log($method, $url, $options['query'] ?? [], $data, $response->getStatusCode());

        if ($response->getStatusCode() >= 400) {
            throw SalesforceException::fromResponse($response);
        }

        return $data ?? [];
    }

    public function query(string $soql): array
    {
        $data = $this->request('GET', $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/query', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Accept' => 'application/json',
            ],
            'query' => ['q' => $soql],
        ]);

        return $data['records'] ?? [];
    }

    public function describe(string $object): array
    {
        $url = $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/sobjects/'.trim($object, '/').'/describe';

        $data = $this->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Accept' => 'application/json',
            ],
        ]);

        return $data;
    }

    public function picklistValues(string $object, string $recordTypeId, string $field): array
    {
        $url = $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/ui-api/object-info/'.trim($object, '/').'/picklist-values/'.trim($recordTypeId, '/').'/'.trim($field, '/');

        $data = $this->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Accept' => 'application/json',
            ],
        ]);

        return array_values(array_map(
            fn ($v) => html_entity_decode($v['value'], ENT_QUOTES | ENT_HTML5),
            $data['values'] ?? []
        ));
    }

    public function create(string $object, array $payload): array
    {
        $url = $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/sobjects/'.trim($object, '/');

        return $this->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => $payload,
        ]);
    }

    public function update(string $object, string $id, array $payload): array
    {
        $url = $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/sobjects/'.trim($object, '/').'/'.trim($id, '/');

        return $this->request('PATCH', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => $payload,
        ]);
    }

    public function delete(string $object, string $id): array
    {
        $url = $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/sobjects/'.trim($object, '/').'/'.trim($id, '/');

        return $this->request('DELETE', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Accept' => 'application/json',
            ],
        ]);
    }
}

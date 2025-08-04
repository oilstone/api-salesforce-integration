<?php

namespace Oilstone\ApiSalesforceIntegration\Clients;

use GuzzleHttp\Client;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Exceptions\SalesforceException;
use Psr\Log\LoggerInterface;

class Salesforce
{
    protected Client $httpClient;

    protected string $instanceUrl;

    protected string $accessToken;

    protected string $instanceVersion;

    protected ?LoggerInterface $logger = null;

    protected ?QueryCacheHandler $cacheHandler = null;

    public function __construct(Client $httpClient, string $instanceUrl, string $accessToken, string $instanceVersion = 'v52.0', ?LoggerInterface $logger = null, ?QueryCacheHandler $cacheHandler = null)
    {
        $this->httpClient = $httpClient;
        $this->instanceUrl = rtrim($instanceUrl, '/');
        $this->accessToken = $accessToken;
        $this->instanceVersion = $instanceVersion;
        $this->logger = $logger;
        $this->cacheHandler = $cacheHandler;
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

    protected function request(string $method, string $url, array $options = []): array
    {
        $response = $this->httpClient->request($method, $url, array_merge([
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ], $options));

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if ($options['log_request'] ?? false) {
            $this->log($method, $url, $options, $data ?? [], $response->getStatusCode());
        }

        if ($response->getStatusCode() >= 400) {
            throw SalesforceException::fromResponse($response);
        }

        return $data ?? [];
    }

    public function rawQuery(string $soql): array
    {
        return $this->request(
            'GET',
            $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/query',
            ['query' => ['q' => $soql], 'log_request' => true],
        );
    }

    public function query(string $soql): array
    {
        return $this->rawQuery($soql)['records'] ?? [];
    }

    public function describe(string $object): array
    {
        $options = ['log_request' => false];

        $callback = fn () => $this->request(
            'GET',
            $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/sobjects/'.trim($object, '/').'/describe',
            $options,
        );

        if ($this->cacheHandler) {
            return $this->cacheHandler->remember('DESCRIBE '.$object, $callback, [$object], $options);
        }

        return $callback();
    }

    public function picklistValues(string $object, string $recordTypeId, string $field): array
    {
        $options = ['log_request' => false];

        $callback = fn () => $this->request(
            'GET',
            $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/ui-api/object-info/'.trim($object, '/').'/picklist-values/'.trim($recordTypeId, '/').'/'.trim($field, '/'),
            $options,
        );

        $data = $this->cacheHandler ? $this->cacheHandler->remember('PICKLIST_VALUES '.$object.'_'.$recordTypeId.'_'.$field, $callback, [$object, $recordTypeId, $field], $options) : $callback();

        return array_values(array_map(
            fn ($v) => html_entity_decode($v['value'], ENT_QUOTES | ENT_HTML5),
            $data['values'] ?? []
        ));
    }

    public function create(string $object, array $payload): array
    {
        return $this->request('POST', $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/sobjects/'.trim($object, '/'), [
            'json' => $payload,
            'log_request' => true,
        ]);
    }

    public function update(string $object, string $id, array $payload, string $identifier = 'Id'): array
    {
        $url = $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/sobjects/'.trim($object, '/');

        if ($identifier !== 'Id') {
            $url .= '/'.trim($identifier, '/').'/'.trim($id, '/');
        } else {
            $url .= '/'.trim($id, '/');
        }

        return $this->request('PATCH', $url, [
            'json' => $payload,
            'log_request' => true,
        ]);
    }

    public function delete(string $object, string $id, string $identifier = 'Id'): array
    {
        $url = $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/sobjects/'.trim($object, '/');

        if ($identifier !== 'Id') {
            $url .= '/'.trim($identifier, '/').'/'.trim($id, '/');
        } else {
            $url .= '/'.trim($id, '/');
        }

        return $this->request('DELETE', $url, ['log_request' => true]);
    }
}

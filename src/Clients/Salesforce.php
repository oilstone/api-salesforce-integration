<?php

namespace Oilstone\ApiSalesforceIntegration\Clients;

use GuzzleHttp\Client;

class Salesforce
{
    protected Client $httpClient;

    protected string $instanceUrl;

    protected string $accessToken;

    public function __construct(Client $httpClient, string $instanceUrl, string $accessToken)
    {
        $this->httpClient = $httpClient;
        $this->instanceUrl = rtrim($instanceUrl, '/');
        $this->accessToken = $accessToken;
    }

    public function query(string $soql): array
    {
        $response = $this->httpClient->request('GET', $this->instanceUrl.'/services/data/v52.0/query', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Accept' => 'application/json',
            ],
            'query' => ['q' => $soql],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return $data['records'] ?? [];
    }
}

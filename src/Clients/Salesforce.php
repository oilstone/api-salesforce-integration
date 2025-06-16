<?php

namespace Oilstone\ApiSalesforceIntegration\Clients;

use GuzzleHttp\Client;

class Salesforce
{
    protected Client $httpClient;

    protected string $instanceUrl;

    protected string $accessToken;

    protected string $instanceVersion;

    public function __construct(Client $httpClient, string $instanceUrl, string $accessToken, string $instanceVersion = 'v52.0')
    {
        $this->httpClient = $httpClient;
        $this->instanceUrl = rtrim($instanceUrl, '/');
        $this->accessToken = $accessToken;
        $this->instanceVersion = $instanceVersion;
    }

    public function query(string $soql): array
    {
        $response = $this->httpClient->request('GET', $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/query', [
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

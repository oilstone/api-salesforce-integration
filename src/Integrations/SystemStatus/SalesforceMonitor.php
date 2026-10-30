<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\SystemStatus;

use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\SystemStatus\Concerns\MakesHttpRequests;
use Oilstone\SystemStatus\Concerns\RatesUsage;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Runs a SOQL query with the client's credentials and rates the org's daily API usage from the
 * Sforce-Limit-Info response header. Each check counts as one request against that limit.
 *
 * Options:
 * - query: SOQL to run (default: SELECT Name, IsSandbox, InstanceName FROM Organization LIMIT 1)
 * - degrade_usage: daily API usage percentage that degrades the check (default 80)
 * - max_usage: daily API usage percentage that fails the check (default 95)
 * - timeout, connect_timeout, options: see MakesHttpRequests
 */
class SalesforceMonitor extends Monitor
{
    use MakesHttpRequests, RatesUsage;

    protected const DEFAULT_QUERY = 'SELECT Name, IsSandbox, InstanceName FROM Organization LIMIT 1';

    protected string $name = 'Salesforce';

    protected array $thresholds = [1000 => 'optimal', 3000 => 'acceptable'];

    public function __construct(array $config, protected Salesforce $salesforce)
    {
        // The client is assigned first, because the parent constructor calls configure()
        parent::__construct($config);
    }

    public function check(Probe $probe): void
    {
        $response = $this->authenticatedRequest('GET', $this->apiUrl('query'), [
            'query' => ['q' => $this->config['query'] ?? static::DEFAULT_QUERY],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            $this->fail(sprintf('GET %s did not return a JSON object', $this->describeUrl($this->apiUrl('query'))));
        }

        $record = $data['records'][0] ?? null;

        if (is_array($record)) {
            if (isset($record['Name'])) {
                $probe->meta('organisation', $record['Name']);
            }

            if (isset($record['IsSandbox'])) {
                $probe->meta('sandbox', (bool) $record['IsSandbox']);
            }

            if (isset($record['InstanceName'])) {
                $probe->meta('instance', $record['InstanceName']);
            }
        }

        $this->rateApiUsage($probe, $response);
    }

    protected function configure(): void
    {
        if ($this->blank($this->instanceUrl())) {
            throw InvalidMonitorConfiguration::missing($this->name(), ['instance_url']);
        }

        $this->validateUsageLimits();
    }

    protected function usageDefaults(): array
    {
        return ['degrade_usage' => 80, 'max_usage' => 95];
    }

    /**
     * Send a request with the client's access token. Salesforce rejects an expired token with a
     * 401, so the token is refreshed once and the request retried, which must then succeed.
     */
    protected function authenticatedRequest(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = $this->request($method, $url, $this->withToken($options, $this->token()), [200, 401]);

        if ($response->getStatusCode() === 401) {
            $response = $this->request($method, $url, $this->withToken($options, $this->token(refresh: true)));
        }

        return $response;
    }

    protected function withToken(array $options, string $token): array
    {
        $options['headers'] = ['Authorization' => 'Bearer '.$token]
            + ($options['headers'] ?? [])
            + ['Accept' => 'application/json'];

        return $options;
    }

    protected function token(bool $refresh = false): string
    {
        $tokenManager = $this->salesforce->getTokenManager();

        try {
            return $refresh ? $tokenManager->refresh() : $tokenManager->getToken();
        } catch (Throwable $e) {
            $this->fail('Could not obtain an access token: '.$this->redactUrls($e->getMessage()), $e);
        }
    }

    /**
     * Rate the "api-usage=used/limit" figure Salesforce reports on every REST response.
     */
    protected function rateApiUsage(Probe $probe, ResponseInterface $response): void
    {
        if (! preg_match('~api-usage=(\d+)/(\d+)~', $response->getHeaderLine('Sforce-Limit-Info'), $matches)) {
            return;
        }

        $used = (int) $matches[1];
        $limit = (int) $matches[2];

        $probe->meta('api_usage', $used.'/'.$limit);

        $this->rateUsage($probe, 'Daily API', $used, $limit);
    }

    /**
     * Absolute REST URL for the client's API version, e.g. https://example.my.salesforce.com/services/data/v52.0/query.
     */
    protected function apiUrl(string $path = ''): string
    {
        return $this->instanceUrl().'/services/data/'.$this->apiVersion().'/'.ltrim($path, '/');
    }

    protected function instanceUrl(): string
    {
        return $this->salesforce->getInstanceUrl();
    }

    protected function apiVersion(): string
    {
        return $this->salesforce->getInstanceVersion();
    }
}

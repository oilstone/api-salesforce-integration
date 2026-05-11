<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel\Auth;

use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Oilstone\ApiSalesforceIntegration\Auth\TokenManager;

class SalesforceTokenManager implements TokenManager
{
    public function __construct(
        protected Client $httpClient,
        protected CacheRepository $cache,
        protected string $instanceUrl,
        protected string $clientId,
        protected string $clientSecret,
        protected array|string|null $scopes = null,
        protected string $cacheKey = 'salesforce.access_token',
        protected string $lockKey = 'salesforce.access_token.lock',
        protected int $safetyMargin = 60,
    ) {}

    public function getToken(): string
    {
        $cached = $this->cache->get($this->cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->fetch();
    }

    public function refresh(): string
    {
        $this->cache->forget($this->cacheKey);

        return $this->fetch();
    }

    protected function fetch(): string
    {
        $lock = $this->cache->lock($this->lockKey, 10);
        $acquired = false;

        try {
            $acquired = $lock->block(5);
        } catch (LockTimeoutException) {
            $acquired = false;
        }

        try {
            $cached = $this->cache->get($this->cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            return $this->requestNewToken();
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }
        }
    }

    protected function requestNewToken(): string
    {
        $formParams = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        $scope = $this->normaliseScopes();

        if ($scope !== '') {
            $formParams['scope'] = $scope;
        }

        $response = $this->httpClient->post($this->instanceUrl . '/services/oauth2/token', [
            'form_params' => $formParams,
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data) || ! isset($data['access_token'])) {
            throw new \RuntimeException('Unable to retrieve Salesforce access token.');
        }

        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3300;
        $ttl = max(60, $expiresIn - $this->safetyMargin);

        $this->cache->put($this->cacheKey, $data['access_token'], $ttl);

        return $data['access_token'];
    }

    protected function normaliseScopes(): string
    {
        if (is_array($this->scopes)) {
            return implode(' ', array_filter($this->scopes));
        }

        if (is_string($this->scopes)) {
            return trim($this->scopes);
        }

        return '';
    }
}

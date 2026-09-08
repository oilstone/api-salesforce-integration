<?php

namespace Oilstone\ApiSalesforceIntegration\Clients;

use GuzzleHttp\Client;
use Oilstone\ApiSalesforceIntegration\Auth\StaticTokenManager;
use Oilstone\ApiSalesforceIntegration\Auth\TokenManager;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Exceptions\SalesforceException;
use Psr\Log\LoggerInterface;

class Salesforce
{
    protected Client $httpClient;

    protected string $instanceUrl;

    protected TokenManager $tokenManager;

    protected string $instanceVersion;

    protected ?LoggerInterface $logger = null;

    protected ?QueryCacheHandler $cacheHandler = null;

    /**
     * In-process memo of describe payloads, keyed by lower-cased object name. Relationship
     * resolution calls describe() once per relation per record, so this saves a cache
     * round trip (or an HTTP request without a cache handler) for every call after the first.
     *
     * @var array<string, array>
     */
    protected array $describeCache = [];

    public function __construct(
        Client $httpClient,
        string $instanceUrl,
        string|TokenManager $accessToken,
        string $instanceVersion = 'v52.0',
        ?LoggerInterface $logger = null,
        ?QueryCacheHandler $cacheHandler = null,
    ) {
        $this->httpClient = $httpClient;
        $this->instanceUrl = rtrim($instanceUrl, '/');
        $this->tokenManager = $accessToken instanceof TokenManager
            ? $accessToken
            : new StaticTokenManager($accessToken);
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

    protected function request(string $method, string $url, array $options = [], bool $isRetry = false): array
    {
        $logRequest = (bool) ($options['log_request'] ?? false);

        $requestOptions = $options;
        unset($requestOptions['log_request']);

        // Merge headers rather than replace them, so caller-supplied headers (e.g. on
        // apexRest) never drop the Authorization header.
        $requestOptions['headers'] = array_merge([
            'Authorization' => 'Bearer '.$this->tokenManager->getToken(),
            'Accept' => 'application/json',
        ], $requestOptions['headers'] ?? []);

        $requestOptions += ['http_errors' => false];

        $response = $this->httpClient->request($method, $url, $requestOptions);

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if ($logRequest) {
            $this->log($method, $url, $options, $data ?? [], $response->getStatusCode());
        }

        if ($response->getStatusCode() === 401 && ! $isRetry) {
            $this->tokenManager->refresh();

            return $this->request($method, $url, $options, true);
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
        $object = trim($object, '/');
        $memoKey = strtolower($object);

        if (isset($this->describeCache[$memoKey])) {
            return $this->describeCache[$memoKey];
        }

        $options = ['log_request' => false];

        $callback = fn () => $this->request(
            'GET',
            $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/sobjects/'.$object.'/describe',
            $options,
        );

        $describe = $this->cacheHandler
            ? $this->cacheHandler->rememberSchema('DESCRIBE '.$object, $callback, $options)
            : $callback();

        return $this->describeCache[$memoKey] = $describe;
    }

    /**
     * Drop the in-process describe memo, for one object or all of them. Call this after
     * flushing the schema cache in a long-running process so the next describe() re-reads.
     */
    public function forgetDescribe(?string $object = null): static
    {
        if ($object === null) {
            $this->describeCache = [];
        } else {
            unset($this->describeCache[strtolower(trim($object, '/'))]);
        }

        return $this;
    }

    public function picklistValues(string $object, string $recordTypeId, string $field): array
    {
        $options = ['log_request' => false];

        $callback = fn () => $this->request(
            'GET',
            $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/ui-api/object-info/'.trim($object, '/').'/picklist-values/'.trim($recordTypeId, '/').'/'.trim($field, '/'),
            $options,
        );

        $data = $this->cacheHandler
            ? $this->cacheHandler->rememberSchema('PICKLIST_VALUES '.$object.'_'.$recordTypeId.'_'.$field, $callback, $options)
            : $callback();

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
        $url = $this->instanceUrl.$this->sobjectRowPath($object, $id, $identifier);

        return $this->request('PATCH', $url, [
            'json' => $payload,
            'log_request' => true,
        ]);
    }

    public function upsert(string $object, string $identifierValue, array $payload, string $identifier = 'Id'): array
    {
        return $this->update($object, $identifierValue, $payload, $identifier);
    }

    public function delete(string $object, string $id, string $identifier = 'Id'): array
    {
        $url = $this->instanceUrl.$this->sobjectRowPath($object, $id, $identifier);

        return $this->request('DELETE', $url, ['log_request' => true]);
    }

    /**
     * Execute several REST subrequests in one HTTP round trip (one API call against limits).
     * Subrequests run in order and may reference earlier results with "@{referenceId.field}".
     *
     * With $allOrNone the whole batch is rolled back when any subrequest fails; the failing
     * subrequest's errors are raised as a SalesforceException (subrequests reported as
     * PROCESSING_HALTED are skipped in favour of the real cause).
     *
     * @param  array<int, array{method: string, url: string, referenceId: string, body?: array}>  $subrequests
     * @return array<string, array{status: int, body: mixed}>  keyed by referenceId
     */
    public function composite(array $subrequests, bool $allOrNone = true): array
    {
        $response = $this->request('POST', $this->instanceUrl.$this->apiPath('composite'), [
            'json' => [
                'allOrNone' => $allOrNone,
                'compositeRequest' => array_values($subrequests),
            ],
            'log_request' => true,
        ]);

        $results = [];
        $failure = null;

        foreach ($response['compositeResponse'] ?? [] as $index => $subresponse) {
            $reference = (string) ($subresponse['referenceId'] ?? $index);
            $status = (int) ($subresponse['httpStatusCode'] ?? 0);
            $body = $subresponse['body'] ?? null;

            $results[$reference] = ['status' => $status, 'body' => $body];

            if ($status < 400) {
                continue;
            }

            $errors = is_array($body) && $body !== []
                ? (array_is_list($body) ? $body : [$body])
                : [['message' => sprintf('Composite subrequest "%s" failed', $reference), 'errorCode' => 'HTTP_'.$status]];

            if ($failure === null || ($failure[0][0]['errorCode'] ?? '') === 'PROCESSING_HALTED') {
                $failure = [$errors, $status];
            }
        }

        if ($failure !== null) {
            throw new SalesforceException($failure[0], $failure[1]);
        }

        return $results;
    }

    public function createSubrequest(string $referenceId, string $object, array $payload): array
    {
        return [
            'method' => 'POST',
            'url' => $this->apiPath('sobjects/'.trim($object, '/')),
            'referenceId' => $referenceId,
            'body' => $payload,
        ];
    }

    public function updateSubrequest(string $referenceId, string $object, string $id, array $payload, string $identifier = 'Id'): array
    {
        return [
            'method' => 'PATCH',
            'url' => $this->sobjectRowPath($object, $id, $identifier),
            'referenceId' => $referenceId,
            'body' => $payload,
        ];
    }

    public function querySubrequest(string $referenceId, string $soql): array
    {
        // Encode the SOQL but keep any "@{reference.field}" tokens intact so Salesforce can
        // substitute them before running the subrequest.
        $encoded = preg_replace('/%40%7B([A-Za-z0-9_.]+)%7D/', '@{$1}', rawurlencode($soql));

        return [
            'method' => 'GET',
            'url' => $this->apiPath('query').'?q='.$encoded,
            'referenceId' => $referenceId,
        ];
    }

    /**
     * Relative REST path for the configured API version, e.g. /services/data/v52.0/query.
     */
    public function apiPath(string $path): string
    {
        return '/services/data/'.$this->instanceVersion.'/'.ltrim($path, '/');
    }

    protected function sobjectRowPath(string $object, string $id, string $identifier = 'Id'): string
    {
        $path = 'sobjects/'.trim($object, '/');

        if ($identifier !== 'Id') {
            $path .= '/'.trim($identifier, '/');
        }

        return $this->apiPath($path.'/'.trim($id, '/'));
    }

    public function flowAction(string $flowName, array $inputs): array
    {
        return $this->request(
            'POST',
            $this->instanceUrl.'/services/data/'.$this->instanceVersion.'/actions/custom/flow/'.trim($flowName, '/'),
            [
                'json' => [
                    'inputs' => $inputs,
                ],
                'log_request' => true,
            ],
        );
    }

    public function apexRest(string $method, string $path, array $options = []): array
    {
        $url = $this->instanceUrl.'/services/apexrest/'.ltrim($path, '/');

        return $this->request($method, $url, array_merge(['log_request' => true], $options));
    }
}

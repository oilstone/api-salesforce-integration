<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\SystemStatus;

use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\SystemStatus\Probe;

/**
 * The fastest authenticated request Salesforce answers: the resource list for the client's API
 * version, which runs no SOQL. Use it for frequent checks and watches. Each check still counts
 * as one request against the org's daily API limit.
 *
 * Options:
 * - timeout: seconds for the whole request (default 3)
 * - connect_timeout: seconds to establish a connection (default 2)
 * - options: see MakesHttpRequests
 */
class SalesforceHeartbeatMonitor extends SalesforceMonitor
{
    protected string $name = 'Salesforce heartbeat';

    protected array $thresholds = [500 => 'optimal', 1500 => 'acceptable'];

    public function __construct(array $config, Salesforce $salesforce)
    {
        $config['timeout'] ??= 3;
        $config['connect_timeout'] ??= 2;

        parent::__construct($config, $salesforce);
    }

    public function check(Probe $probe): void
    {
        $this->authenticatedRequest('GET', $this->apiUrl());
    }
}

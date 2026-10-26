<?php

namespace AgilePixel\AllsWell;

use AgilePixel\AllsWell\Http\Response;
use AgilePixel\AllsWell\Http\Transport;

/**
 * The two calls the package makes to All's Well.
 */
final class Client
{
    /**
     * @var Transport
     */
    private $transport;

    public function __construct(Transport $transport)
    {
        $this->transport = $transport;
    }

    /**
     * @param  array{url: string, name: string|null}  $site
     */
    public function enrol(string $origin, string $credential, string $platform, array $site, ?string $currentToken, int $timeout): Response
    {
        return $this->transport->post(rtrim($origin, '/').'/site/enrol', $credential, [
            'protocol' => 1,
            'platform' => $platform,
            'addon_version' => AllsWell::VERSION,
            'site' => ['url' => $site['url'], 'name' => $site['name']],
            'current_token' => $currentToken,
        ], $timeout);
    }

    /**
     * @param  array<mixed>  $report
     */
    public function report(string $reportUrl, string $token, array $report, int $timeout): Response
    {
        return $this->transport->post($reportUrl, $token, $report, $timeout);
    }
}

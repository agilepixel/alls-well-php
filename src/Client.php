<?php

namespace AgilePixel\AllsWell;

use AgilePixel\AllsWell\Connect\AuthorizationRequest;
use AgilePixel\AllsWell\Http\Response;
use AgilePixel\AllsWell\Http\Transport;

/**
 * The calls the package makes to All's Well.
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
     * Enrol with the team's key.
     *
     * @param  array{url: string, name: string|null}  $site
     * @param  string|null  $addonVersion  The package's own when null.
     */
    public function enrol(string $origin, string $credential, string $platform, array $site, ?string $currentToken, int $timeout, ?string $addonVersion = null): Response
    {
        return $this->transport->post(self::endpoint($origin), $credential, self::enrolment($platform, $site, $currentToken, $addonVersion), $timeout);
    }

    /**
     * Enrol with the one-time code someone's approval gave, for the site as it
     * was approved and with the verifier the code was issued for.
     *
     * @param  string|null  $addonVersion  The package's own when null.
     */
    public function exchange(AuthorizationRequest $request, string $code, ?string $currentToken, int $timeout, ?string $addonVersion = null): Response
    {
        $site = ['url' => $request->siteUrl(), 'name' => $request->siteName()];
        $body = self::enrolment($request->platform(), $site, $currentToken, $addonVersion);
        $body['code_verifier'] = $request->verifier();

        return $this->transport->post(self::endpoint($request->origin()), $code, $body, $timeout);
    }

    /**
     * @param  array<mixed>  $report
     */
    public function report(string $reportUrl, string $token, array $report, int $timeout): Response
    {
        return $this->transport->post($reportUrl, $token, $report, $timeout);
    }

    private static function endpoint(string $origin): string
    {
        return rtrim($origin, '/').'/site/enrol';
    }

    /**
     * @param  array{url: string, name: string|null}  $site
     * @return array<string, mixed>
     */
    private static function enrolment(string $platform, array $site, ?string $currentToken, ?string $addonVersion): array
    {
        return [
            'protocol' => 1,
            'platform' => $platform,
            'addon_version' => $addonVersion !== null ? $addonVersion : AllsWell::VERSION,
            'site' => ['url' => $site['url'], 'name' => $site['name']],
            'current_token' => $currentToken,
        ];
    }
}

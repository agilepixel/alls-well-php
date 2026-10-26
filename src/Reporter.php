<?php

namespace AgilePixel\AllsWell;

use AgilePixel\AllsWell\Http\Response;
use AgilePixel\AllsWell\Report\ComposerPackages;
use AgilePixel\AllsWell\Report\ReportBuilder;
use AgilePixel\AllsWell\State\State;
use AgilePixel\AllsWell\Support\ClassName;
use AgilePixel\AllsWell\Support\Credentials;
use AgilePixel\AllsWell\Support\Url;

/**
 * Decides whether to enrol, report, pause or wait, sends the report and
 * records All's Well's answer. It never throws: whatever fails comes back as
 * a failed Result and one log line naming only the exception's class.
 *
 * A key-mode connection belongs to the address it was enrolled for. A copy of
 * the app (the same state file at another address) never reports with it: it
 * enrols as a new site, without sending the old token, and a light report
 * from a copy sends nothing at all.
 *
 * In token mode the stored state belongs to the configured token. A state
 * saved for another token, or for none, is set aside, so a new token is never
 * held back by an old one's pause or back-off.
 */
final class Reporter
{
    const MIN_INTERVAL = 3600;

    const MAX_INTERVAL = 86400;

    const FIRST_BACKOFF = 300;

    const MAX_BACKOFF = 86400;

    const MAX_REPORT_BACKOFF = 3600;

    const UNKNOWN_TOKEN_BACKOFF = 86400;

    const MIN_RETRY_AFTER = 60;

    const DUE_EARLY = 300;

    const SITE_MOVED_FALLBACK = "All's Well refused a report from this site's address.";

    const SITE_NAME_LIMIT = 255;

    /** @var Config */
    private $config;

    /** @var Client */
    private $client;

    /**
     * Read from Composer when a report is first built, unless given.
     *
     * @var ComposerPackages|null
     */
    private $packages;

    /** @var callable|null */
    private $clock;

    /**
     * @param  ComposerPackages|null  $packages  The installed packages to report; read from Composer when null.
     * @param  callable|null  $clock  Returns the time in unix seconds; time() when null.
     */
    public function __construct(Config $config, Client $client, ?ComposerPackages $packages = null, ?callable $clock = null)
    {
        $this->config = $config;
        $this->client = $client;
        $this->packages = $packages;
        $this->clock = $clock;
    }

    /**
     * Report to All's Well, enrolling first when a key is set and this
     * address has no connection yet. A scheduled report waits until the
     * interval All's Well asked for has nearly passed; other reasons don't.
     *
     * @param  string  $reason  One of the protocol's reasons; 'deactivated' and 'uninstalled' are light.
     */
    public function report(string $reason = 'scheduled'): Result
    {
        try {
            $refusal = $this->refusal();
            if ($refusal !== null) {
                return $refusal;
            }

            $now = $this->now();
            $state = $this->loadState();
            if ($state->paused() !== null) {
                return $this->pausedResult($state);
            }

            $unusable = $this->unusableState();
            if ($unusable !== null) {
                return $unusable;
            }

            if ($this->config->isKeyMode()) {
                $connection = $this->keyModeConnection($state, in_array($reason, ReportBuilder::LIGHT_REASONS, true), $now);
                if ($connection instanceof Result) {
                    return $connection;
                }
                $state = $connection;
            }

            if ($state->backoffUntil() > $now) {
                return self::waiting();
            }

            if ($reason === 'scheduled' && self::notDue($state, $now)) {
                return new Result(Result::SKIPPED, 'Not due yet.');
            }

            return $this->send($reason, $state, $now);
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }
    }

    /**
     * Connect by hand: lift any pause and, with a key, enrol now whatever the
     * back-off. The same address re-enrols with the token it holds, so it
     * stays one site in All's Well, and keeps reporting with that token if
     * re-enrolling fails; a copy enrols as a new site. Once connected it
     * sends an `activated` report, whatever the back-off, and returns how
     * that went.
     */
    public function connect(): Result
    {
        try {
            $refusal = $this->refusal();
            if ($refusal !== null) {
                return $refusal;
            }

            $unusable = $this->unusableState();
            if ($unusable !== null) {
                return $unusable;
            }

            $now = $this->now();
            $state = $this->loadState()->with(['paused' => null]);

            if (! $this->config->isKeyMode()) {
                $failed = $this->save($state);

                return $failed !== null ? $failed : $this->send('activated', $state, $now);
            }

            $currentToken = null;
            if ($state->isConnected() && $this->isSameSite($state)) {
                $currentToken = $state->siteToken();
            } elseif ($state->isConnected()) {
                $state = $state->forgetConnection();
            }
            $failed = $this->save($state);
            if ($failed !== null) {
                return $failed;
            }

            $enrolled = $this->enrol($state, $currentToken, $now);
            if ($enrolled instanceof Result) {
                return $enrolled;
            }

            return $this->send('activated', $enrolled, $now);
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }
    }

    /**
     * Stop reporting until `connect`: tell All's Well the site is going (an
     * `uninstalled` report, best effort and only from a connection made for
     * this address), then pause as disconnected. With a key the connection is
     * forgotten too. Nothing is sent when the pause could not be kept (no
     * persistent store, or one with a problem): the next report would
     * contradict it, and All's Well would raise a false alert.
     */
    public function disconnect(): Result
    {
        try {
            $refusal = $this->refusal();
            if ($refusal !== null) {
                return $refusal;
            }

            if (! $this->config->state()->isPersistent()) {
                return new Result(Result::FAILED, $this->config->wording()->disconnectNotKept(), null, true);
            }

            $problem = $this->config->state()->problem();
            if ($problem !== null) {
                return new Result(Result::FAILED, $problem, null, true);
            }

            $now = $this->now();
            $state = $this->loadState();

            $sent = null;
            if ($state->paused() === null && $this->isConnectedHere($state)) {
                try {
                    $sent = $this->send('uninstalled', $state, $now);
                } catch (\Throwable $e) {
                    $this->config->log("All's Well: unexpected error (".ClassName::of($e).').');
                }
                $state = $this->loadState();
            }

            if ($this->config->isKeyMode()) {
                $state = $state->forgetConnection();
            }
            $failed = $this->save($state->with(['paused' => State::PAUSED_DISCONNECTED]), $sent !== null ? $sent->status() : null);
            if ($failed !== null) {
                return $failed;
            }

            $message = 'Disconnected. Remove ALLS_WELL_TOKEN or ALLS_WELL_KEY to stop for good.';
            if (! $this->config->isKeyMode()) {
                $message .= " The site stays in All's Well until it's deleted there.";
            }

            return new Result(
                $sent !== null && $sent->outcome() === Result::SENT ? Result::SENT : Result::SKIPPED,
                $message,
                $sent !== null ? $sent->status() : null
            );
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }
    }

    /**
     * What the package knows, for the owner to read. It never sends anything
     * and never throws. A site deleted in All's Well isn't connected, even
     * while its token is still set.
     *
     * @return array{mode: 'token'|'key'|null, site_url: string|null, origin: string|null, connected: bool, paused: string|null, last_report_at: int|null, last_attempt_at: int|null, last_status: int|null, last_error: string|null, backoff_until: int, errors: list<string>, persistent: bool}
     */
    public function status(): array
    {
        try {
            $errors = array_values($this->config->errors());
            $problem = $this->config->state()->problem();
            if ($problem !== null) {
                $errors[] = $problem;
            }
            $credentials = $this->config->credentials();
            $mode = $credentials->siteToken() !== null ? 'token' : ($this->config->isKeyMode() ? 'key' : null);

            try {
                $state = $this->loadState();
            } catch (\Throwable $e) {
                $state = State::empty();
                $errors[] = 'The saved state could not be read ('.ClassName::of($e).').';
            }

            $connected = $mode !== null && $state->paused() !== State::PAUSED_DELETED && $this->isConnectedHere($state);
            $origin = $state->origin();

            return [
                'mode' => $mode,
                'site_url' => $this->config->siteUrl(),
                'origin' => $connected && $origin !== null ? $origin : $this->config->origin(),
                'connected' => $connected,
                'paused' => $state->paused(),
                'last_report_at' => $state->lastReportAt(),
                'last_attempt_at' => $state->lastAttemptAt(),
                'last_status' => $state->lastStatus(),
                'last_error' => $state->lastError(),
                'backoff_until' => $state->backoffUntil(),
                'errors' => $errors,
                'persistent' => $this->config->state()->isPersistent(),
            ];
        } catch (\Throwable $e) {
            $class = ClassName::of($e);
            $this->config->log("All's Well: unexpected error (".$class.').');

            return [
                'mode' => null,
                'site_url' => null,
                'origin' => null,
                'connected' => false,
                'paused' => null,
                'last_report_at' => null,
                'last_attempt_at' => null,
                'last_status' => null,
                'last_error' => null,
                'backoff_until' => 0,
                'errors' => ['Unexpected error ('.$class.').'],
                'persistent' => false,
            ];
        }
    }

    /**
     * Turned off, or set up wrongly: either way nothing is built or sent.
     *
     * @return Result|null
     */
    private function refusal()
    {
        if (! $this->config->enabled()) {
            return new Result(Result::SKIPPED, "All's Well reporting is turned off (ALLS_WELL_ENABLED).");
        }

        $errors = $this->config->errors();
        if ($errors !== []) {
            return new Result(Result::FAILED, $errors[0], null, true);
        }

        return null;
    }

    /**
     * The key-mode connection to report with: the stored one when it was made
     * for this address, otherwise a new one from enrolling, or why there is none.
     *
     * @param  bool  $light  Whether the report is a light one (deactivated, uninstalled).
     * @param  int  $now
     * @return State|Result
     */
    private function keyModeConnection(State $state, $light, $now)
    {
        if ($state->isConnected() && $this->isSameSite($state)) {
            return $state;
        }

        if ($light) {
            return $state->isConnected()
                ? new Result(Result::PAUSED, "This copy of the site isn't connected, so nothing was sent.")
                : new Result(Result::SKIPPED, "This site isn't connected to All's Well, so nothing was sent.");
        }

        if ($state->isConnected()) {
            $state = $state->forgetConnection();
            $failed = $this->save($state);
            if ($failed !== null) {
                return $failed;
            }
        } elseif ($state->backoffUntil() > $now) {
            return self::waiting();
        }

        return $this->enrol($state, null, $now);
    }

    /**
     * Save the state, or say why it couldn't be: the owner has to fix where
     * it is kept, so the failure needs attention whatever came before. The
     * message names no path; the log names the exception's class.
     *
     * @param  int|null  $status  The HTTP status this run's request was answered with, if any.
     * @param  bool  $reportSent  Whether this run's report was already sent and accepted.
     * @return Result|null Null once saved.
     */
    private function save(State $state, $status = null, $reportSent = false)
    {
        try {
            $this->config->state()->save($state);

            return null;
        } catch (\Throwable $e) {
            $name = $this->config->state()->name();
            $this->config->log("All's Well: ".$name.' could not be saved ('.ClassName::of($e).').');

            $message = $reportSent
                ? 'The report was sent, but '.$name.' could not be saved.'
                : ucfirst($name).' could not be saved.';

            return new Result(Result::FAILED, $message, $status, true);
        }
    }

    /**
     * Why this run must stop before contacting All's Well: the store has a
     * problem and holds no connection it could read for this configuration.
     * Enrolling then would lose the token (or make a second site), and a
     * report would ignore a pause it can't see. A connection the store did
     * read still reports, and a save that then fails says so. So does a
     * site token when only saving would fail: the token can't be lost, and
     * no pause is hidden, so stopping would only let the site go Down.
     *
     * @return Result|null
     */
    private function unusableState()
    {
        $store = $this->config->state();
        $problem = $store->problem();
        if ($problem === null || $this->holdsConnection($store->load())) {
            return null;
        }

        if ($this->config->credentials()->siteToken() !== null && ! $store->isUnreadable()) {
            return null;
        }

        return new Result(Result::FAILED, $problem, null, true);
    }

    /**
     * Whether the stored state, as read, has a connection this configuration
     * reports with: the configured site token's, or with a key, one made for
     * this address.
     *
     * @return bool
     */
    private function holdsConnection(State $stored)
    {
        $token = $this->config->credentials()->siteToken();
        if ($token !== null) {
            return self::sameToken($stored->siteToken(), $token);
        }

        return $stored->isConnected() && $this->isSameSite($stored);
    }

    /**
     * @param  string|null  $stored
     * @param  string  $token
     * @return bool
     */
    private static function sameToken($stored, $token)
    {
        return $stored !== null && strtolower($stored) === strtolower($token);
    }

    /**
     * The stored state, as this configuration may use it. In token mode a
     * state saved for another token, or for none, is replaced by an empty
     * one, and the token's connection is merged in: the configured origin,
     * and the stored report_url only while it is on that origin (so a
     * followed app move sticks). The address the token reports for is not:
     * it is recorded only once All's Well accepts a report from it
     * (recordSuccess()), so a copy it refuses never looks like this site.
     *
     * @return State
     */
    private function loadState()
    {
        $state = $this->config->state()->load();
        $token = $this->config->credentials()->siteToken();
        if ($token === null) {
            return $state;
        }

        if (! self::sameToken($state->siteToken(), $token)) {
            $state = State::empty();
        }

        $origin = $this->config->origin();
        $reportUrl = $state->reportUrl();

        return $state->with([
            'site_token' => $token,
            'origin' => $origin,
            'report_url' => $reportUrl !== null && Url::sameOrigin($origin, $reportUrl) ? $reportUrl : $origin.'/site/report',
        ]);
    }

    /**
     * Whether there is a connection to report with from this address: always
     * with a site token, and with a key only when it was made for this address.
     *
     * @return bool
     */
    private function isConnectedHere(State $state)
    {
        if (! $this->config->isKeyMode()) {
            return $this->config->credentials()->siteToken() !== null;
        }

        return $state->isConnected() && $this->isSameSite($state);
    }

    /**
     * Whether a scheduled report would come too soon: All's Well asked for
     * one every interval, and five minutes' slack lets an hourly cron that
     * runs a little early still report.
     *
     * @param  int  $now
     * @return bool
     */
    private static function notDue(State $state, $now)
    {
        $last = $state->lastReportAt();

        return $last !== null && $last + $state->intervalSeconds() - self::DUE_EARLY > $now;
    }

    /**
     * The reporting path, reached once every check has passed: build the
     * report, send it with the connection in $state and record the answer.
     * The report is built only here, so the owner's checks run only when a
     * report goes out.
     *
     * @param  string  $reason
     * @param  int  $now
     * @return Result
     */
    private function send($reason, State $state, $now)
    {
        $token = $state->siteToken();
        $reportUrl = $state->reportUrl();
        if ($token === null || $reportUrl === null) {
            return new Result(Result::SKIPPED, "This site isn't connected to All's Well, so nothing was sent.");
        }

        $report = (new ReportBuilder($this->config, $this->packages()))->build($reason, $now);
        $response = $this->client->report($reportUrl, $token, $report, $this->config->timeout());

        return $this->record($response, $state, $now);
    }

    /**
     * Record All's Well's answer to a report, as the WordPress add-on's
     * record() does. Every answer stamps the attempt and its status.
     *
     * Only the owner can fix a 409 site_moved, a 410 or a 422, so those need
     * attention. Everything else is retried by itself, and needs attention
     * only until a report has succeeded: All's Well can't notice a site that
     * has never reported.
     *
     * @param  int  $now
     * @return Result
     */
    private function record(Response $response, State $state, $now)
    {
        $status = $response->status();
        $neverReported = $this->neverReported($state);
        $changes = ['last_attempt_at' => $now, 'last_status' => $status];

        if ($status === 200) {
            return $this->recordSuccess($response, $state, $changes, $now);
        }

        if ($status === 409 && $response->errorCode() === 'site_moved') {
            $message = self::oneLine((string) $response->message());
            $state = $state->with($changes + [
                'paused' => State::PAUSED_SITE_MOVED,
                'last_error' => $message === '' ? self::SITE_MOVED_FALLBACK : $message,
            ]);
            $failed = $this->save($state, $status);

            return $failed !== null ? $failed : new Result(Result::PAUSED, $this->pauseMessage($state), $status, true);
        }

        if ($status === 410) {
            if ($this->config->isKeyMode()) {
                $state = $state->forgetConnection();
            }
            $state = $state->with($changes + [
                'paused' => State::PAUSED_DELETED,
                'last_error' => "This site was deleted in All's Well.",
            ]);
            $failed = $this->save($state, $status);

            return $failed !== null ? $failed : new Result(Result::PAUSED, $this->pauseMessage($state), $status, true);
        }

        if ($status === 401) {
            $changes['backoff_until'] = $now + self::UNKNOWN_TOKEN_BACKOFF;
            $changes['last_error'] = "All's Well does not recognise this site's token.";
        } elseif ($status === 422) {
            $message = self::oneLine((string) $response->message());
            $changes['last_error'] = $message === '' ? "All's Well rejected the report." : "All's Well rejected the report: ".$message;
        } elseif ($status === 429) {
            $changes['backoff_until'] = $now + min(self::MAX_BACKOFF, max(self::MIN_RETRY_AFTER, (int) $response->retryAfter()));
            $changes['last_error'] = "All's Well is busy, so the report will be tried again later.";
        } else {
            $failures = $state->failures() + 1;
            $changes['failures'] = $failures;
            $changes['backoff_until'] = $now + min(self::MAX_REPORT_BACKOFF, self::FIRST_BACKOFF * (1 << min($failures - 1, 4)));
            $changes['last_error'] = $status === 0 ? self::unreachable($response) : "All's Well answered with HTTP ".$status.'.';
        }

        $failed = $this->save($state->with($changes), $status);

        return $failed !== null ? $failed : new Result(Result::FAILED, $changes['last_error'], $status, $status === 422 || $neverReported);
    }

    /**
     * Record a report All's Well accepted: clear the failures, back-off and
     * pause, take the interval it asks for and follow it if it moved. A state
     * that can't be saved now needs attention whatever came before: All's
     * Well sees the reports, so it will never alert, and the owner has to fix
     * where the state is kept.
     *
     * In token mode, accepting the report is what binds the token to this
     * address: the connection is saved with the site key, so switching the
     * same app to a key later goes on reporting as this site, while a copy
     * All's Well refused (409 site_moved) keeps the address it had.
     *
     * @param  array<string, int|string|null>  $changes
     * @param  int  $now
     * @return Result
     */
    private function recordSuccess(Response $response, State $state, array $changes, $now)
    {
        $body = $response->body();
        $changes += [
            'last_report_at' => $now,
            'last_error' => null,
            'backoff_until' => 0,
            'failures' => 0,
            'paused' => null,
        ];
        if (isset($body['interval_seconds'])) {
            $changes['interval_seconds'] = self::interval($body['interval_seconds']);
        }
        if (! $this->config->isKeyMode()) {
            $changes['enrolled_site_key'] = Url::siteKey((string) $this->config->siteUrl());
        }

        $failed = $this->save($state->with($this->followAppMove($state, $body) + $changes), 200, true);

        return $failed !== null ? $failed : new Result(Result::SENT, 'Report sent.', 200);
    }

    /**
     * Where to report from now on when All's Well has moved, as the WordPress
     * add-on's follow_app_move() does. All's Well still answers at the address
     * it moved from and names the new one. The move is followed only from a
     * connection made at another address than the configured origin, and
     * only to that origin, so an answer can never send the token elsewhere.
     *
     * @param  array<mixed>  $body
     * @return array<string, string>
     */
    private function followAppMove(State $state, array $body)
    {
        $origin = $this->config->origin();
        $reportUrl = self::text($body, 'report_url');
        if (Url::sameOrigin($origin, (string) $state->origin()) || $reportUrl === null || ! Url::sameOrigin($origin, $reportUrl)) {
            return [];
        }

        $changes = ['origin' => $origin, 'report_url' => $reportUrl];
        $dashboardUrl = self::text($body, 'dashboard_url');
        if ($dashboardUrl !== null && Url::sameOrigin($origin, $dashboardUrl)) {
            $changes['dashboard_url'] = $dashboardUrl;
        }

        return $changes;
    }

    /**
     * @return ComposerPackages
     */
    private function packages()
    {
        if ($this->packages === null) {
            $this->packages = new ComposerPackages;
        }

        return $this->packages;
    }

    /**
     * Enrol with the team key and store the site token All's Well returns,
     * or record why not and back off.
     *
     * @param  string|null  $currentToken  The token already held for this address, so enrolling again returns it.
     * @param  int  $now
     * @return State|Result The saved state once enrolled, otherwise the failure.
     */
    private function enrol(State $state, $currentToken, $now)
    {
        $origin = $this->config->origin();
        $response = $this->client->enrol(
            $origin,
            (string) $this->config->credentials()->enrolmentKey(),
            $this->config->platform(),
            ['url' => (string) $this->config->siteUrl(), 'name' => $this->siteName()],
            $currentToken,
            $this->config->timeout()
        );

        $body = $response->body();
        $token = self::text($body, 'site_token');
        $reportUrl = self::text($body, 'report_url');

        if ($response->isSuccess() && $reportUrl !== null && ! Url::sameOrigin($origin, $reportUrl)) {
            return new Result(
                Result::FAILED,
                "All's Well answered with an address on another host, so the token was not stored.",
                $response->status(),
                true
            );
        }

        if (! $response->isSuccess() || $reportUrl === null || $token === null || Credentials::kind($token) !== 'token') {
            return $this->enrolmentFailed($state, $response, $now, $currentToken === null);
        }

        $dashboardUrl = self::text($body, 'dashboard_url');
        $siteId = isset($body['site_id']) && (is_string($body['site_id']) || is_int($body['site_id'])) ? (string) $body['site_id'] : null;

        $state = $state->with([
            'site_token' => $token,
            'site_id' => $siteId,
            'origin' => $origin,
            'report_url' => $reportUrl,
            'dashboard_url' => $dashboardUrl !== null && Url::sameOrigin($origin, $dashboardUrl) ? $dashboardUrl : null,
            'enrolled_site_key' => Url::siteKey((string) $this->config->siteUrl()),
            'interval_seconds' => self::interval(isset($body['interval_seconds']) ? $body['interval_seconds'] : null),
            'failures' => 0,
            'backoff_until' => 0,
            'paused' => null,
            'last_attempt_at' => $now,
            'last_status' => $response->status(),
            'last_error' => null,
        ]);
        $failed = $this->save($state, $response->status());

        return $failed !== null ? $failed : $state;
    }

    /**
     * Record a failed enrolment. Enrolling for a site with no connection backs
     * off: exponentially from five minutes to a day, or as long as a 429 asks
     * (one minute to a day). Re-enrolling a connection that still works (by
     * hand, with its token) only records the answer, so its reports go on.
     *
     * @param  int  $now
     * @param  bool  $backOff
     * @return Result
     */
    private function enrolmentFailed(State $state, Response $response, $now, $backOff)
    {
        $status = $response->status();
        $message = self::enrolmentError($response);
        $changes = [
            'last_attempt_at' => $now,
            'last_status' => $status,
            'last_error' => $message,
        ];

        if ($backOff) {
            $failures = $state->failures() + 1;
            $changes['failures'] = $failures;
            $changes['backoff_until'] = $now + ($status === 429
                ? min(self::MAX_BACKOFF, max(self::MIN_RETRY_AFTER, (int) $response->retryAfter()))
                : min(self::MAX_BACKOFF, self::FIRST_BACKOFF * (1 << min($failures - 1, 9))));
        }

        $failed = $this->save($state->with($changes), $status);

        return $failed !== null ? $failed : new Result(Result::FAILED, $message, $status, true);
    }

    /**
     * Why an enrolment failed, in words the site's owner can act on: the
     * server's message when it sends one, except for a 409, where the
     * package knows the fix (ALLS_WELL_TOKEN) better than the server.
     *
     * @return string
     */
    private static function enrolmentError(Response $response)
    {
        $status = $response->status();

        if ($status === 0) {
            return self::unreachable($response);
        }
        if ($status === 409) {
            return "This site is already connected and reporting. Set ALLS_WELL_TOKEN to its site token, or delete it in All's Well.";
        }
        if ($response->isSuccess()) {
            return "All's Well sent an unexpected answer, so the site was not connected.";
        }

        $message = self::oneLine((string) $response->message());
        if ($message !== '') {
            return $message;
        }

        if ($status === 401) {
            return "All's Well did not accept the enrolment key.";
        }

        return $status === 403 || $status === 422
            ? "All's Well refused the enrolment (HTTP ".$status.').'
            : "All's Well answered with HTTP ".$status.'.';
    }

    /**
     * Why there was no answer, as one line.
     *
     * @return string
     */
    private static function unreachable(Response $response)
    {
        $error = self::oneLine((string) $response->error());

        return $error === '' ? "Could not reach All's Well." : "Could not reach All's Well: ".$error;
    }

    /**
     * A pause read back from the state: announced on the run it began, so it
     * doesn't need attention again.
     *
     * @return Result
     */
    private function pausedResult(State $state)
    {
        return new Result(Result::PAUSED, $this->pauseMessage($state));
    }

    /**
     * What the owner can do about a pause. A deleted site comes back with a
     * key by enrolling again; a site token belongs to the deleted site for
     * good, so `connect` would only be refused again.
     *
     * @return string
     */
    private function pauseMessage(State $state)
    {
        $wording = $this->config->wording();

        switch ($state->paused()) {
            case State::PAUSED_DELETED:
                $message = $this->config->isKeyMode()
                    ? "This site was deleted in All's Well. Run ".$wording->connect().' to connect it again.'
                    : $wording->deletedWithToken();
                break;
            case State::PAUSED_DISCONNECTED:
                $message = 'Reporting was turned off with '.$wording->disconnect().'. Run '.$wording->connect().' to turn it back on.';
                break;
            case State::PAUSED_MOVED:
                $message = "This site's address changed since it was connected. Run ".$wording->connect().' to connect it again.';
                break;
            default:
                $error = self::oneLine((string) $state->lastError());
                $message = ($error === '' ? self::SITE_MOVED_FALLBACK : $error).' Run '.$wording->connect().' once fixed.';
        }

        return $message;
    }

    /**
     * @return Result
     */
    private static function waiting()
    {
        return new Result(Result::SKIPPED, "Waiting before trying All's Well again.");
    }

    /**
     * An exception from anywhere below: logged and returned by class name
     * only, since a message can carry paths or values. The log line names
     * All's Well because the host's log has no other context.
     *
     * @return Result
     */
    private function unexpected(\Throwable $e)
    {
        $class = ClassName::of($e);
        $this->config->log("All's Well: unexpected error (".$class.').');

        return new Result(Result::FAILED, 'Unexpected error ('.$class.').', null, $this->neverReported());
    }

    /**
     * Whether the stored state has never seen a report succeed. All's Well
     * can't notice a site that has never reported, so until then every
     * failure needs the owner's attention.
     *
     * Without a state file nothing is remembered between runs, so this is
     * never known, and treating every failure as the first would print on
     * every cron run, all through an All's Well outage. Those failures stay
     * quiet instead: All's Well's own alerts cover a site that stops
     * reporting or never starts, and a run in a terminal still prints.
     *
     * @return bool
     */
    private function neverReported(?State $state = null)
    {
        if (! $this->config->state()->isPersistent()) {
            return false;
        }

        try {
            return ($state !== null ? $state : $this->loadState())->lastReportAt() === null;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Whether the stored connection was enrolled for the address configured now.
     *
     * @return bool
     */
    private function isSameSite(State $state)
    {
        $key = Url::siteKey((string) $this->config->siteUrl());

        return $key !== null && $state->enrolledSiteKey() === $key;
    }

    /**
     * @return string|null The site's name within the protocol's limit; null when there is none.
     */
    private function siteName()
    {
        $name = $this->config->siteName();
        if ($name === '') {
            return null;
        }

        return function_exists('mb_substr')
            ? mb_substr($name, 0, self::SITE_NAME_LIMIT, 'UTF-8')
            : substr($name, 0, self::SITE_NAME_LIMIT);
    }

    /**
     * @return int Unix seconds.
     */
    private function now()
    {
        if ($this->clock === null) {
            return time();
        }

        $now = call_user_func($this->clock);

        return is_int($now) && $now >= 0 ? $now : time();
    }

    /**
     * @param  mixed  $seconds  interval_seconds from All's Well.
     * @return int Clamped to an hour – a day.
     */
    private static function interval($seconds)
    {
        $seconds = is_int($seconds) ? $seconds : self::MIN_INTERVAL;

        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $seconds));
    }

    /**
     * @param  array<mixed>  $body
     * @param  string  $key
     * @return string|null A non-empty string from the answer, or null.
     */
    private static function text(array $body, $key)
    {
        return isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '' ? $body[$key] : null;
    }

    /**
     * Control characters (line breaks, terminal escapes) become spaces, so a
     * message prints as one line.
     *
     * @param  string  $text
     * @return string
     */
    private static function oneLine($text)
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }
}

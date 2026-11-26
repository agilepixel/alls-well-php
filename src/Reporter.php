<?php

namespace AgilePixel\AllsWell;

use AgilePixel\AllsWell\Connect\AuthorizationRequest;
use AgilePixel\AllsWell\Http\Response;
use AgilePixel\AllsWell\Report\ComposerPackages;
use AgilePixel\AllsWell\Report\ReportBuilder;
use AgilePixel\AllsWell\State\State;
use AgilePixel\AllsWell\Support\ClassName;
use AgilePixel\AllsWell\Support\Credentials;
use AgilePixel\AllsWell\Support\Url;

/**
 * Decides whether to enrol, report, pause or wait, sends the report and
 * records the answer from All's Well. Only authorizationRequest() can throw: whatever else fails comes
 * back as a failed Result and one log line naming only the exception's class.
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

    private const FIRST_REPORT = 'Connected. The first report will be tried again: ';

    private const REPLACE = 'This site is already connected to All\'s Well and reporting. Click Connect again and tick "Replace the existing connection".';

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
            $light = in_array($reason, ReportBuilder::LIGHT_REASONS, true);
            $refusal = $light && $this->isFarewell() ? null : $this->refusal();
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

            if ($this->config->mode() !== 'token') {
                $connection = $this->config->isKeyMode()
                    ? $this->keyModeConnection($state, $light, $now)
                    : $this->storedConnection($state, $light);
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
     * that went. A connection kept in the state is resumed, but never made
     * here: connectWithCode() and connectWithCredential() make one. With a
     * key, one made in another environment is let go only once enrolling has
     * given another, since the environment may only have been renamed.
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
                $unmade = $this->config->mode() === 'stored' ? $this->unmadeConnection($state) : null;
                if ($unmade !== null) {
                    return $unmade;
                }
                $failed = $this->save($state);

                return $failed !== null ? $failed : $this->send('activated', $state, $now);
            }

            if ($this->copyPause($state) !== null) {
                $failed = $this->probe($this->loadState());

                return $failed !== null ? $failed : $this->connectTo(
                    $this->enrolWith((string) $this->config->credentials()->enrolmentKey(), null),
                    $this->config->origin(),
                    $state,
                    $now,
                    $this->config->wording()->siteExists()
                );
            }

            $currentToken = $this->heldToken($state);
            if ($currentToken === null && $state->isConnected()) {
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
     * Why this site can't be connected from a screen, for the screen to say
     * instead of offering to. It sends nothing and never throws.
     *
     * @return string|null Null when it can.
     */
    public function connectProblem(): ?string
    {
        try {
            $refusal = $this->connectRefusal();

            return $refusal !== null ? $refusal->message() : null;
        } catch (\Throwable $e) {
            return $this->unexpected($e)->message();
        }
    }

    /**
     * Start connecting this site with a button. Keep the request in the
     * session of the person who clicked, send their browser to its url(), and
     * give it to connectWithCode() when they come back.
     *
     * @param  string  $returnUrl  Where the browser comes back to: one AuthorizationRequest::canReturnTo() allows.
     *
     * @throws \Exception When PHP has no source of randomness.
     */
    public function authorizationRequest(string $returnUrl): AuthorizationRequest
    {
        return AuthorizationRequest::create(
            $this->config->origin(),
            $this->config->platform(),
            (string) $this->config->siteUrl(),
            $this->siteName(),
            $returnUrl,
            $this->now()
        );
    }

    /**
     * Connect with the one-time code the browser came back with. The code is
     * traded where the request was made and for the site as it was approved,
     * sent nowhere else and never kept. Unless that gives a connection,
     * whatever is stored stays as it is.
     *
     * @param  mixed  $state  The state that came back with the code, which must be the request's.
     * @param  mixed  $code
     */
    public function connectWithCode(AuthorizationRequest $request, $state, $code): Result
    {
        try {
            $refusal = $this->connectRefusal();
            if ($refusal !== null) {
                return $refusal;
            }

            $now = $this->now();
            if (! $request->matches($state) || $request->isExpired($now)) {
                return self::refused('That connection request expired. Click Connect again.');
            }
            if (! is_string($code) || ! AuthorizationRequest::isCode($code)) {
                return self::refused("All's Well could not connect this site. Click Connect again.");
            }
            if (! Url::sameSite($request->siteUrl(), (string) $this->config->siteUrl())) {
                return self::refused("This site's address changed while it was being connected. Click Connect again.");
            }

            $stored = $this->loadState();
            $failed = $this->probe($stored);
            if ($failed !== null) {
                return $failed;
            }

            $response = $this->client->exchange($request, $code, $this->heldToken($stored), $this->config->timeout(), $this->config->addonVersion());

            return $this->connectTo($response, $request->origin(), $stored, $now, self::REPLACE);
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }
    }

    /**
     * Connect with what someone typed: the site's token, or the team's key,
     * which is used once and never kept. A connection that works is replaced
     * only once All's Well has taken the new one. With nothing connected, a
     * token is also kept when All's Well couldn't be reached to check it.
     */
    public function connectWithCredential(string $credential): Result
    {
        try {
            $refusal = $this->connectRefusal();
            if ($refusal !== null) {
                return $refusal;
            }

            $now = $this->now();
            $credential = trim($credential);
            $kind = Credentials::kind($credential);
            if ($kind === null) {
                return self::refused('That is neither a site token nor an enrolment key.');
            }

            $stored = $this->loadState();
            $failed = $this->probe($stored);
            if ($failed !== null) {
                return $failed;
            }

            return $kind === 'token'
                ? $this->connectToToken(strtolower($credential), $stored, $now)
                : $this->connectTo($this->enrolWith($credential, $this->heldToken($stored)), $this->config->origin(), $stored, $now, $this->config->wording()->siteExists());
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

            if ($this->config->mode() !== 'token') {
                $state = $state->forgetConnection();
            }
            $failed = $this->save($state->with(['paused' => State::PAUSED_DISCONNECTED]), $sent !== null ? $sent->status() : null);
            if ($failed !== null) {
                return $failed;
            }

            $message = $this->config->wording()->disconnected();
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
     * A copy of a kept connection, at another address or in another
     * environment, isn't connected either: `paused` then says which.
     *
     * A scheduled report goes out from `next_report_at` on. It is null while
     * none would.
     *
     * @return array{mode: 'token'|'key'|'stored'|null, site_url: string|null, origin: string|null, dashboard_url: string|null, connected: bool, paused: string|null, last_report_at: int|null, last_attempt_at: int|null, last_status: int|null, last_error: string|null, backoff_until: int, next_report_at: int|null, errors: list<string>, persistent: bool}
     */
    public function status(): array
    {
        try {
            $errors = array_values($this->config->errors());
            $problem = $this->config->state()->problem();
            if ($problem !== null) {
                $errors[] = $problem;
            }
            $mode = $this->config->mode();

            $next = null;

            try {
                $state = $this->loadState();
                $next = $this->nextReportAt($state);
            } catch (\Throwable $e) {
                $state = State::empty();
                $errors[] = 'The saved state could not be read ('.ClassName::of($e).').';
            }

            $connected = $state->paused() !== State::PAUSED_DELETED && $this->isConnectedHere($state);
            $origin = $state->origin();

            return [
                'mode' => $mode,
                'site_url' => $this->config->siteUrl(),
                'origin' => $connected && $origin !== null ? $origin : $this->config->origin(),
                'dashboard_url' => $connected ? $state->dashboardUrl() : null,
                'connected' => $connected,
                'paused' => $state->paused() !== null ? $state->paused() : $this->copyPause($state),
                'last_report_at' => $state->lastReportAt(),
                'last_attempt_at' => $state->lastAttemptAt(),
                'last_status' => $state->lastStatus(),
                'last_error' => $state->lastError(),
                'backoff_until' => $state->backoffUntil(),
                'next_report_at' => $next,
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
                'dashboard_url' => null,
                'connected' => false,
                'paused' => null,
                'last_report_at' => null,
                'last_attempt_at' => null,
                'last_status' => null,
                'last_error' => null,
                'backoff_until' => 0,
                'next_report_at' => null,
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
     * Whether a light report is only a goodbye from a connection that is
     * already held. A host that is being removed sends it from memory, its
     * own store being gone, and the key needs nowhere to keep a token then.
     *
     * @return bool
     */
    private function isFarewell()
    {
        return $this->config->enabled() && $this->config->errors(false) === [] && $this->heldToken($this->loadState()) !== null;
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
        if ($this->heldToken($state) !== null) {
            return $state;
        }

        if ($light) {
            return $state->isConnected() ? self::copyResult() : self::notConnectedResult();
        }

        $paused = $this->copyPause($state);
        if ($paused !== null) {
            return new Result(Result::PAUSED, $this->pauseMessage($state, $paused));
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
     * The connection kept in the state, or why it can't be used. A copy of it
     * sends and saves nothing: it may be a database pulled from the live site,
     * and one that moved on purpose is connected again by hand.
     *
     * @param  bool  $light
     * @return State|Result
     */
    private function storedConnection(State $state, $light)
    {
        if (! self::isKept($state)) {
            return self::notConnectedResult();
        }

        $paused = $this->copyPause($state);
        if ($paused === null) {
            return $state;
        }

        return $light ? self::copyResult() : new Result(Result::PAUSED, $this->pauseMessage($state, $paused));
    }

    /**
     * Why there is no kept connection to resume, for the owner to act on.
     *
     * @return Result|null Null when there is one.
     */
    private function unmadeConnection(State $state)
    {
        if (! self::isKept($state)) {
            return new Result(Result::FAILED, $this->config->wording()->notConnected(), null, true);
        }

        $paused = $this->copyPause($state);

        return $paused === null ? null : new Result(Result::FAILED, $this->pauseMessage($state, $paused), null, true);
    }

    /**
     * @return Result
     */
    private static function notConnectedResult()
    {
        return new Result(Result::SKIPPED, "This site isn't connected to All's Well, so nothing was sent.");
    }

    /**
     * @return Result
     */
    private static function copyResult()
    {
        return new Result(Result::PAUSED, "This copy of the site isn't connected, so nothing was sent.");
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

        return $token !== null ? self::sameToken($stored->siteToken(), $token) : $this->isConnectedHere($stored);
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
     * Where connections are kept, what a token left behind goes unseen once
     * ALLS_WELL_TOKEN no longer sets it: its pause would be nobody's to lift.
     *
     * @return State
     */
    private function loadState()
    {
        $state = $this->config->state()->load();
        $token = $this->config->credentials()->siteToken();
        if ($token === null) {
            return $this->isLeftBehind($state) ? State::empty() : $state;
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
     * Whether the state is that of a token ALLS_WELL_TOKEN once set.
     *
     * @return bool
     */
    private function isLeftBehind(State $state)
    {
        return $this->config->mode() === 'stored' && $state->siteToken() !== null && ! $state->wasMadeHere();
    }

    /**
     * Whether there is a connection to report with from here: always with a
     * site token, otherwise only one made for this install.
     *
     * @return bool
     */
    private function isConnectedHere(State $state)
    {
        switch ($this->config->mode()) {
            case 'token':
                return true;
            case 'key':
                return $this->heldToken($state) !== null;
            case 'stored':
                return self::isKept($state) && $this->difference($state) === null;
            default:
                return false;
        }
    }

    /**
     * Whether the state holds a connection that was made here. One copied
     * from ALLS_WELL_TOKEN isn't: taking that setting away stops the site.
     *
     * @return bool
     */
    private static function isKept(State $state)
    {
        return $state->isConnected() && $state->wasMadeHere();
    }

    /**
     * @return string|null The stored token, when the connection was made for this install.
     */
    private function heldToken(State $state)
    {
        return $state->isConnected() && $this->difference($state) === null ? $state->siteToken() : null;
    }

    /**
     * How this install differs from the one the stored connection was made
     * for: another address, or another environment. One without a name is
     * another than one with: pausing the live site once by mistake is alerted
     * and put right, but a copy that reports as the live site is neither.
     *
     * @return string|null State::PAUSED_MOVED, State::PAUSED_ENVIRONMENT, or null when it is the same.
     */
    private function difference(State $state)
    {
        if (! $this->isSameSite($state)) {
            return State::PAUSED_MOVED;
        }

        return $state->enrolledEnvironment() !== $this->config->boundEnvironment() ? State::PAUSED_ENVIRONMENT : null;
    }

    /**
     * The pause a copied connection is under, which is never saved. With a
     * key, a copy at another address enrols as a site of its own instead.
     *
     * @return string|null
     */
    private function copyPause(State $state)
    {
        if ($this->config->mode() === 'stored') {
            return self::isKept($state) ? $this->difference($state) : null;
        }

        return $this->config->isKeyMode() && $state->isConnected() && $this->difference($state) === State::PAUSED_ENVIRONMENT
            ? State::PAUSED_ENVIRONMENT
            : null;
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
        $due = self::dueAt($state);

        return $due !== null && $due > $now;
    }

    /**
     * @return int|null When the interval All's Well asked for has passed, nearly; null before the first report.
     */
    private static function dueAt(State $state)
    {
        $last = $state->lastReportAt();

        return $last === null ? null : $last + $state->intervalSeconds() - self::DUE_EARLY;
    }

    /**
     * When report() would next send a scheduled report, or enrol first: now
     * for one that was never sent, and the past for one that is overdue.
     *
     * @return int|null Null while it would send none.
     */
    private function nextReportAt(State $state)
    {
        if ($this->refusal() !== null || $state->paused() !== null || $this->copyPause($state) !== null || $this->unusableState() !== null) {
            return null;
        }

        $isConnected = $this->isConnectedHere($state);
        if (! $isConnected && ! $this->config->isKeyMode()) {
            return null;
        }

        $due = $isConnected ? self::dueAt($state) : null;

        return max($due !== null ? $due : $this->now(), $state->backoffUntil());
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
        if (! $state->isConnected()) {
            return self::notConnectedResult();
        }

        return $this->record($this->deliver($reason, $state, $now), $state, $now);
    }

    /**
     * Build the report and post it with the connection in $state.
     *
     * @param  string  $reason
     * @param  int  $now
     * @return Response
     */
    private function deliver($reason, State $state, $now)
    {
        $report = (new ReportBuilder($this->config, $this->packages()))->build($reason, $now);

        return $this->client->report((string) $state->reportUrl(), (string) $state->siteToken(), $report, $this->config->timeout());
    }

    /**
     * Record the answer from All's Well to a report.
     *
     * @param  int  $now
     * @return Result
     */
    private function record(Response $response, State $state, $now)
    {
        [$next, $result] = $this->outcome($response, $state, $now);
        $failed = $this->save($next, $response->status(), $response->status() === 200);

        return $failed !== null ? $failed : $result;
    }

    /**
     * What an answer to a report comes to: the state to save and the result.
     * Every answer stamps the attempt and its status.
     *
     * Only the owner can fix a 409 site_moved, a 410 or a 422, so those need
     * attention. Everything else is retried by itself, and needs attention
     * only until a report has succeeded: All's Well can't notice a site that
     * has never reported.
     *
     * @param  int  $now
     * @return array{0: State, 1: Result}
     */
    private function outcome(Response $response, State $state, $now)
    {
        $status = $response->status();
        $changes = ['last_attempt_at' => $now, 'last_status' => $status];

        if ($status === 200) {
            return [$this->afterSuccess($response, $state, $changes, $now), new Result(Result::SENT, 'Report sent.', 200)];
        }

        if ($status === 409 && $response->errorCode() === 'site_moved') {
            $message = self::oneLine((string) $response->message());
            $state = $state->with($changes + [
                'paused' => State::PAUSED_SITE_MOVED,
                'last_error' => $message === '' ? self::SITE_MOVED_FALLBACK : $message,
            ]);

            return [$state, new Result(Result::PAUSED, $this->pauseMessage($state), $status, true)];
        }

        if ($status === 410) {
            if ($this->config->mode() !== 'token') {
                $state = $state->forgetConnection();
            }
            $state = $state->with($changes + [
                'paused' => State::PAUSED_DELETED,
                'last_error' => "This site was deleted in All's Well.",
            ]);

            return [$state, new Result(Result::PAUSED, $this->pauseMessage($state), $status, true)];
        }

        if ($status === 401) {
            $changes['backoff_until'] = $now + self::UNKNOWN_TOKEN_BACKOFF;
            $changes['last_error'] = "All's Well does not recognise this site's token.";
        } elseif ($status === 422) {
            $changes['last_error'] = self::reportRejected(self::oneLine((string) $response->message()));
        } elseif ($status === 429) {
            $changes['backoff_until'] = $now + min(self::MAX_BACKOFF, max(self::MIN_RETRY_AFTER, (int) $response->retryAfter()));
            $changes['last_error'] = "All's Well is busy, so the report will be tried again later.";
        } else {
            $failures = $state->failures() + 1;
            $changes['failures'] = $failures;
            $changes['backoff_until'] = $now + min(self::MAX_REPORT_BACKOFF, self::FIRST_BACKOFF * (1 << min($failures - 1, 4)));
            $changes['last_error'] = $status === 0 ? self::unreachable($response) : "All's Well answered with HTTP ".$status.'.';
        }

        return [
            $state->with($changes),
            new Result(Result::FAILED, $changes['last_error'], $status, $status === 422 || $this->neverReported($state)),
        ];
    }

    /**
     * The state after a report All's Well accepted: clear the failures,
     * back-off and pause, take the interval it asks for and follow it if it
     * moved. When it can't be saved the owner is told whatever came before:
     * All's Well sees the reports, so it will never alert, and the owner has
     * to fix where the state is kept.
     *
     * In token mode, accepting the report is what binds the token to this
     * address and environment: the connection is saved with the site key, so
     * switching the same app to a key later goes on reporting as this site,
     * while a copy All's Well refused (409 site_moved) keeps what it had.
     *
     * @param  array<string, int|string|null>  $changes
     * @param  int  $now
     * @return State
     */
    private function afterSuccess(Response $response, State $state, array $changes, $now)
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
        if ($this->config->mode() === 'token') {
            $changes['enrolled_site_key'] = Url::siteKey((string) $this->config->siteUrl());
            $changes['enrolled_environment'] = $this->config->boundEnvironment();
        }
        $dashboardUrl = self::text($body, 'dashboard_url');
        if ($dashboardUrl !== null && Url::sameOrigin($state->origin(), $dashboardUrl) && ! Url::sameOrigin($state->origin(), (string) $state->dashboardUrl())) {
            $changes['dashboard_url'] = $dashboardUrl;
        }

        return $state->with($this->followAppMove($state, $body) + $changes);
    }

    /**
     * Where to report from now on when All's Well has moved. All's Well still
     * answers at the address it moved from and names the new one. The move is
     * followed only from a connection made at another address than the
     * configured origin, and only to that origin, so an answer can never send
     * the token elsewhere.
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
     * Enrol with the set key and store the site token All's Well returns, or
     * record why not and back off.
     *
     * @param  string|null  $currentToken  The token already held for this address, so enrolling again returns it.
     * @param  int  $now
     * @return State|Result The saved state once enrolled, otherwise the failure.
     */
    private function enrol(State $state, $currentToken, $now)
    {
        $response = $this->enrolWith((string) $this->config->credentials()->enrolmentKey(), $currentToken);
        $connection = $this->connectionIn($response, $this->config->origin(), $now);
        if ($connection instanceof Result) {
            return $connection;
        }
        if ($connection === null) {
            return $this->enrolmentFailed($state, $response, $now, $currentToken === null);
        }

        $state = $state->with($connection);
        $failed = $this->save($state, $response->status());

        return $failed !== null ? $failed : $state;
    }

    /**
     * @param  string  $key
     * @param  string|null  $currentToken
     * @return Response
     */
    private function enrolWith($key, $currentToken)
    {
        return $this->client->enrol(
            $this->config->origin(),
            $key,
            $this->config->platform(),
            ['url' => (string) $this->config->siteUrl(), 'name' => $this->siteName()],
            $currentToken,
            $this->config->timeout(),
            $this->config->addonVersion()
        );
    }

    /**
     * Keep the connection an answer gives and send its first report, or say
     * why there is none, leaving what is stored alone.
     *
     * @param  string  $origin
     * @param  int  $now
     * @param  string  $siteExists  What to do when the site is already connected and reporting.
     * @return Result
     */
    private function connectTo(Response $response, $origin, State $state, $now, $siteExists)
    {
        $connection = $this->connectionIn($response, $origin, $now);
        if ($connection instanceof Result) {
            return $connection;
        }
        if ($connection === null) {
            return self::refused(self::enrolmentError($response, $siteExists), $response->status());
        }

        $state = self::forToken($state, $connection['site_token'])->with($connection);
        $failed = $this->save($state, $response->status());
        if ($failed !== null) {
            return $failed;
        }

        try {
            $first = $this->deliver('activated', $state, $now);
        } catch (\Throwable $e) {
            return new Result(Result::CONNECTED, self::FIRST_REPORT.$this->unexpected($e)->message());
        }

        return $this->recordFirst($first, $state, $now, self::FIRST_REPORT);
    }

    /**
     * Report with a token someone typed and keep it if All's Well takes the
     * report. Any other answer leaves what is stored alone, except that a site
     * with nothing connected keeps the token to try again when All's Well was
     * busy or out of reach.
     *
     * @param  string  $token
     * @param  int  $now
     * @return Result
     */
    private function connectToToken($token, State $stored, $now)
    {
        $origin = $this->config->origin();
        $state = self::forToken($stored, $token)->with([
            'site_token' => $token,
            'origin' => $origin,
            'report_url' => $origin.'/site/report',
        ] + $this->madeHere());

        $response = $this->deliver('activated', $state, $now);
        $status = $response->status();
        $body = $response->body();

        if ($status === 200 && isset($body['protocol']) && $body['protocol'] === 1) {
            return $this->record($response, $state, $now);
        }
        if (in_array($status, [401, 409, 410, 422], true)) {
            return self::refused(self::tokenRefusal($response), $status);
        }

        $replaces = $this->isConnectedHere($stored) && ! self::sameToken($stored->siteToken(), $token);
        if (! $replaces && ($status === 0 || $status === 429 || $status >= 500)) {
            return $this->recordFirst($response, $state, $now, 'The token is saved, and will be tried again: ');
        }

        if ($status === 0) {
            $why = self::unreachable($response);
        } else {
            $why = $status === 200 ? "All's Well sent an unexpected answer." : "All's Well answered with HTTP ".$status.'.';
        }

        return self::refused('That token could not be checked, so nothing changed. '.$why, $status);
    }

    /**
     * Record the answer to the first report of a connection someone just
     * made. Where it will be tried again by itself, the site is connected.
     *
     * @param  int  $now
     * @param  string  $saying  What to say first, then.
     * @return Result
     */
    private function recordFirst(Response $response, State $state, $now, $saying)
    {
        $status = $response->status();
        [$next, $result] = $this->outcome($response, $state, $now);
        $failed = $this->save($next, $status, $status === 200);
        if ($failed !== null) {
            return $failed;
        }

        return $result->outcome() === Result::FAILED && $status !== 401 && $status !== 422
            ? new Result(Result::CONNECTED, $saying.$result->message(), $status)
            : $result;
    }

    /**
     * Save the state as it is stored, to find out before anything is sent
     * whether a connection could be kept: one that All's Well gave and that
     * was then lost would take the site's reporting with it. State that can't
     * be read is left for the connection itself to replace.
     *
     * @return Result|null Null when it can.
     */
    private function probe(State $stored)
    {
        return $this->config->state()->isUnreadable() ? null : $this->save($stored);
    }

    /**
     * The connection in an enrolment's answer, as changes to the state.
     *
     * @param  string  $origin
     * @param  int  $now
     * @return array<string, mixed>|Result|null Null when the answer holds none; a Result when it names another host.
     */
    private function connectionIn(Response $response, $origin, $now)
    {
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
            return null;
        }

        $dashboardUrl = self::text($body, 'dashboard_url');
        $siteId = isset($body['site_id']) && (is_string($body['site_id']) || is_int($body['site_id'])) ? (string) $body['site_id'] : null;

        return [
            'site_token' => $token,
            'site_id' => $siteId,
            'origin' => $origin,
            'report_url' => $reportUrl,
            'dashboard_url' => $dashboardUrl !== null && Url::sameOrigin($origin, $dashboardUrl) ? $dashboardUrl : null,
            'interval_seconds' => self::interval(isset($body['interval_seconds']) ? $body['interval_seconds'] : null),
            'last_attempt_at' => $now,
            'last_status' => $response->status(),
        ] + $this->madeHere();
    }

    /**
     * What every connection made here starts with: whose it is, and no
     * failure, back-off or pause.
     *
     * @return array<string, mixed>
     */
    private function madeHere()
    {
        return [
            'enrolled_site_key' => Url::siteKey((string) $this->config->siteUrl()),
            'enrolled_environment' => $this->config->boundEnvironment(),
            'made_here' => true,
            'failures' => 0,
            'backoff_until' => 0,
            'paused' => null,
            'last_error' => null,
        ];
    }

    /**
     * The state to keep a token's connection in: without what belonged to
     * another token, such as when that one last reported.
     *
     * @param  string  $token
     * @return State
     */
    private static function forToken(State $state, $token)
    {
        return self::sameToken($state->siteToken(), $token) ? $state : $state->forgetConnection();
    }

    /**
     * Why connecting by hand must stop before contacting All's Well: the
     * connection could not be made or kept, or would not be used. Stored
     * state that can't be read is no obstacle, since the connection replaces it.
     *
     * @return Result|null
     */
    private function connectRefusal()
    {
        $refusal = $this->refusal();
        if ($refusal !== null) {
            return self::refused($refusal->message());
        }

        if (Url::hasCredentials((string) $this->config->siteUrl())) {
            return self::refused("This site's address has a username or password in it. Set ".$this->config->wording()->addressSetting().' to the address without them.');
        }
        if (! $this->config->keepsConnection()) {
            return self::refused("A connection can't be kept here. Set ALLS_WELL_TOKEN or ALLS_WELL_KEY instead.");
        }
        if ($this->config->mode() === 'token') {
            return self::refused('ALLS_WELL_TOKEN sets the site this one reports as. Remove it to connect another way.');
        }

        $store = $this->config->state();
        if (! $store->isPersistent()) {
            return self::refused($this->config->wording()->keyNeedsState());
        }
        $problem = $store->problem();

        return $problem !== null && ! $store->isUnreadable() ? self::refused($problem) : null;
    }

    /**
     * @param  string  $message
     * @param  int|null  $status
     * @return Result
     */
    private static function refused($message, $status = null)
    {
        return new Result(Result::FAILED, $message, $status, true);
    }

    /**
     * Why All's Well refused a report made with a token someone typed.
     *
     * @return string
     */
    private static function tokenRefusal(Response $response)
    {
        $message = self::oneLine((string) $response->message());

        switch ($response->status()) {
            case 401:
                return "All's Well does not recognise that site token.";
            case 410:
                return "That site was deleted in All's Well.";
            case 409:
                return $message === '' ? self::SITE_MOVED_FALLBACK : $message;
            default:
                return self::reportRejected($message);
        }
    }

    /**
     * @param  string  $message  What All's Well said of it, if anything.
     * @return string
     */
    private static function reportRejected($message)
    {
        return $message === '' ? "All's Well rejected the report." : "All's Well rejected the report: ".$message;
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
        $message = self::enrolmentError($response, $this->config->wording()->siteExists());
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
     * server's message when it sends one, except for a 409, where the host
     * knows the fix better than the server.
     *
     * @param  string  $siteExists
     * @return string
     */
    private static function enrolmentError(Response $response, $siteExists)
    {
        $status = $response->status();

        if ($status === 0) {
            return self::unreachable($response);
        }
        if ($status === 409) {
            return $siteExists;
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
     * What the owner can do about a pause. A deleted site comes back by
     * connecting again; a site token belongs to the deleted site for good, so
     * `connect` would only be refused again.
     *
     * @param  string|null  $paused  The pause, when it isn't the saved one.
     * @return string
     */
    private function pauseMessage(State $state, $paused = null)
    {
        $wording = $this->config->wording();

        switch ($paused !== null ? $paused : $state->paused()) {
            case State::PAUSED_DELETED:
                $message = $this->config->mode() === 'token'
                    ? $wording->deletedWithToken()
                    : "This site was deleted in All's Well. ".$wording->reconnect();
                break;
            case State::PAUSED_DISCONNECTED:
                $message = 'Reporting was turned off with '.$wording->disconnect().'. '
                    .($this->config->mode() === 'stored' ? $wording->reconnect() : 'Run '.$wording->connect().' to turn it back on.');
                break;
            case State::PAUSED_MOVED:
                $message = "This site's address changed since it was connected. ".$wording->reconnect();
                break;
            case State::PAUSED_ENVIRONMENT:
                $message = $this->environmentMessage($state).' '.$wording->reconnect();
                break;
            default:
                $error = self::oneLine((string) $state->lastError());
                $message = ($error === '' ? self::SITE_MOVED_FALLBACK : $error).' Run '.$wording->connect().' once fixed.';
        }

        return $message;
    }

    /**
     * @return string How the environment here differs from the one the site was connected in.
     */
    private function environmentMessage(State $state)
    {
        $made = $state->enrolledEnvironment();
        $here = $this->config->boundEnvironment();

        return 'This site was connected in '
            .($made === null ? 'an environment with no name' : 'its "'.self::oneLine($made).'" environment')
            .' and '
            .($here === null ? 'this one has no name' : 'this is "'.self::oneLine($here).'"')
            .', so it may be a copy.';
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
     * quiet instead: the alerts in All's Well cover a site that stops
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

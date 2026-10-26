<?php

namespace AgilePixel\AllsWell\Support;

/**
 * The site token and enrolment key, read from two settings that each accept
 * either value and told apart by shape.
 */
final class Credentials
{
    const TOKEN_SETTING = 'ALLS_WELL_TOKEN';

    const KEY_SETTING = 'ALLS_WELL_KEY';

    /** @var string|null */
    private $siteToken;

    /** @var string|null */
    private $enrolmentKey;

    /** @var string[] */
    private $errors;

    /**
     * @param  string|null  $siteToken
     * @param  string|null  $enrolmentKey
     * @param  string[]  $errors
     */
    private function __construct($siteToken, $enrolmentKey, array $errors)
    {
        $this->siteToken = $siteToken;
        $this->enrolmentKey = $enrolmentKey;
        $this->errors = $errors;
    }

    /**
     * @param  string|null  $token  Value of ALLS_WELL_TOKEN.
     * @param  string|null  $key  Value of ALLS_WELL_KEY.
     * @return Credentials
     */
    public static function fromValues($token, $key)
    {
        $siteToken = null;
        $enrolmentKey = null;
        $errors = [];

        foreach ([self::TOKEN_SETTING => $token, self::KEY_SETTING => $key] as $setting => $value) {
            $value = $value === null ? '' : trim($value);
            if ($value === '') {
                continue;
            }

            $kind = self::kind($value);
            if ($kind === 'token') {
                if ($siteToken !== null && strtolower($siteToken) !== strtolower($value)) {
                    $errors[] = self::TOKEN_SETTING.' and '.self::KEY_SETTING.' hold two different site tokens.';

                    continue;
                }
                $siteToken = $siteToken === null ? $value : $siteToken;
            } elseif ($kind === 'key') {
                $enrolmentKey = $enrolmentKey === null ? $value : $enrolmentKey;
            } else {
                $errors[] = $setting.' is neither a site token nor an enrolment key.';
            }
        }

        return new self($siteToken, $enrolmentKey, $errors);
    }

    /**
     * @param  string  $value
     * @return string|null 'token', 'key' or null.
     */
    public static function kind($value)
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return 'token';
        }

        if (preg_match('/^amk_[A-Za-z0-9]{40}$/', $value) === 1) {
            return 'key';
        }

        return null;
    }

    /**
     * @return string|null
     */
    public function siteToken()
    {
        return $this->siteToken;
    }

    /**
     * @return string|null
     */
    public function enrolmentKey()
    {
        return $this->enrolmentKey;
    }

    /**
     * @return string[]
     */
    public function errors()
    {
        return $this->errors;
    }
}

<?php

namespace AgilePixel\AllsWell\Http;

interface Transport
{
    /**
     * POST a JSON body with a bearer credential. Never follows redirects.
     *
     * @param  array<mixed>  $body
     */
    public function post(string $url, string $bearer, array $body, int $timeout): Response;
}

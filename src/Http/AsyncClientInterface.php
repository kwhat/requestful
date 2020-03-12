<?php

namespace Requestful\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Requestful\Futures\PromiseInterface;

interface AsyncClientInterface extends ClientInterface
{
    /**
     * Sends a PSR-7 request asynchronously and returns a PromiseInterface.
     *
     * @phan-suppress PhanTypeInvalidThrowsIsInterface
     *
     * @param RequestInterface $request
     *
     * @return PromiseInterface
     * @throws ClientExceptionInterface
     */
    public function sendRequestAsync(RequestInterface $request): PromiseInterface;
}

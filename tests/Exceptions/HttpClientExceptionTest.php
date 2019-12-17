<?php

namespace Requestful\Test\Exceptions;

use PHPUnit\Framework\TestCase;
use Requestful\Exceptions\HttpClientException;

class HttpClientExceptionTest extends TestCase
{
    public function testGetCodeSuccess()
    {
        $exception = new HttpClientException("bad_request");

        $this->assertEquals("bad_request", $exception->getCode());
    }
}

<?php

namespace Requestful\Test\Exceptions;

use PHPUnit\Framework\TestCase;
use Requestful\Exceptions\CancellationException;

class CancellationExceptionTest extends TestCase
{
    public function testGetCodeSuccess()
    {
        $exception = new CancellationException("canceled");

        $this->assertEquals("canceled", $exception->getMessage());
    }
}

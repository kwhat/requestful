<?php

declare(strict_types=1);

namespace Requestful\Test\Unit\Futures;

use InvalidArgumentException;
use Requestful\Exceptions\PromiseException;
use Requestful\Futures\Promise;
use Requestful\Futures\PromiseInterface;
use PHPUnit\Framework\TestCase;

class PromiseTest extends TestCase
{
    public function testWait()
    {
        $value = "Test Value";

        /** @var PromiseInterface $base */
        $base = new Promise(
            function () use (&$base, $value) {
                $base->resolve($value);
            }
        );

        $promise = $base
            ->then(function ($response) {
                return strtoupper($response);
            })
            ->then(function ($response) {
                return $response;
            })
            ->then(function ($response) {
                return strtolower($response);
            });

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $this->assertEquals(strtolower($value), $promise->wait());
        $this->assertEquals(PromiseInterface::FULFILLED, $promise->getState());
    }

    /**
     * @throws PromiseException
     */
    public function testWaitWithException()
    {
        $value = new PromiseException("Test Value");
        $promise = new Promise(
            function () use (&$promise, $value) {
                throw $value;
            },
            null
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());

        $this->expectException(PromiseException::class);
        $this->expectExceptionCode($value->getCode());
        $this->expectExceptionMessage($value->getMessage());

        $this->assertInstanceOf(PromiseException::class, $promise->wait());
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }

    /**
     * @throws PromiseException
     */
    public function testWaitWithResolvedException()
    {
        $this->expectException(PromiseException::class);

        $value = new PromiseException("Test Value");
        $promise = new Promise(
            function () use (&$promise, $value) {
                /** @var Promise $promise */
                $promise->resolve($value->getMessage());
                throw $value;
            },
            null
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $this->assertSame($promise->wait(), $value);
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }


    /**
     * @throws PromiseException
     */
    public function testWaitWithoutCallback()
    {
        $this->expectException(PromiseException::class);

        $promise = new Promise(null, null);
        $promise->wait();
    }


    /**
     * @throws PromiseException
     */
    public function testCancel()
    {
        $isCanceled = false;
        $promise = new Promise(
            null,
            function (PromiseInterface $promise) use (&$isCanceled) {
                $isCanceled = true;
                $promise->reject("Canceled");
            }
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $promise->cancel();

        $this->assertEquals("Canceled", $promise->wait());
        $this->assertTrue($isCanceled);
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }

    /**
     * @throws PromiseException
     */
    public function testCancelWithException()
    {
        $value = new PromiseException("Test Value");
        $promise = new Promise(
            null,
            function () use (&$promise, $value) {
                throw $value;
            }
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());

        $this->expectException(PromiseException::class);

        $promise->wait();
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }


    public function testThenCallback()
    {
        $value = "Test Value";
        $tmp = new Promise(
            function () use (&$tmp, $value) {
                /** @var Promise $tmp */
                $tmp->resolve($value);
            },
            null
        );

        // Create a new promise from the existing promise with a success callback handler.
        $promise = $tmp->then(
            function ($value) {
                return "New {$value}";
            },
            null
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $this->assertEquals("New {$value}", $promise->wait());
        $this->assertEquals(PromiseInterface::FULFILLED, $promise->getState());
    }

    public function testThenCallbackWithException()
    {
        $value = "Test Value";
        $tmp = new Promise(
            function () use (&$tmp, $value) {
                /** @var Promise $tmp */
                $tmp->resolve($value);
            },
            null
        );

        // Create a new promise from the existing promise with a success callback handler.
        $promise = $tmp->then(
            function ($value) {
                throw new PromiseException($value);
            }
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $this->assertInstanceOf(PromiseException::class, $promise->wait());
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }

    public function testThenCallbackWithCancel()
    {
        $value = "Test Value";
        $tmp = new Promise(
            function () use (&$tmp, $value) {
                /** @var Promise $tmp */
                $tmp->resolve($value);
            },
            null
        );

        // Create a new promise from the existing promise with a success callback handler.
        $promise = $tmp->then(
            function ($value) {
                return $value;
            }
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $promise->cancel();
        $this->assertInstanceOf(PromiseException::class, $promise->wait());
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }

    public function testOtherwiseCallback()
    {
        $tmp = new Promise(
            function () use (&$tmp) {
                /** @var Promise $tmp */
                $tmp->reject("Test Value");
            },
            null
        );

        // Create a new promise from the existing promise with a success callback handler.
        $promise = $tmp->then(
            null,
            function ($value) {
                return str_replace("Test", "New", $value);
            }
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $this->assertEquals("New Value", $promise->wait());
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }

    /**
     * @throws PromiseException
     */
    public function testResolveAgainFailure()
    {
        $this->expectException(PromiseException::class);

        $value = "Test Value";
        $promise = new Promise(
            function () use (&$promise, $value) {
                /** @var Promise $promise */
                $promise->resolve($value);
            },
            null
        );

        $promise->wait();
        $promise->resolve("New {$value}");
    }

    /**
     * @throws PromiseException
     */
    public function testRejectAgainFailure()
    {
        $this->expectException(PromiseException::class);

        $value = "Test Value";
        $promise = new Promise(
            function () use (&$promise, $value) {
                /** @var Promise $promise */
                $promise->resolve($value);
            },
            null
        );

        $promise->wait();
        $promise->reject($value);
    }

    public function testNoCallbacks()
    {
        $this->expectException(InvalidArgumentException::class);

        $promise = new Promise();
        $promise->then();
    }

    public function testDirectAccess()
    {
        $promise = new Promise();
        $promise["test"] = "testing";

        $this->assertTrue(isset($promise["test"]));
        $this->assertFalse(isset($promise["invalid"]));

        unset($promise["test"]);
        $this->assertFalse(isset($promise["test"]));
    }

    public function testIteratorAccess()
    {
        $promise = new Promise();
        for ($i = 1; $i < 5; $i++) {
            $promise->{"test{$i}"} = $i;
        }

        $i = 0;
        foreach ($promise as $property) {
            $i++;
            $this->assertTrue(isset($promise->{"test{$i}"}));
            $this->assertEquals($i, $property);

            unset($promise->test);
            $this->assertFalse(isset($promise->test));
        }

        $this->assertFalse(isset($promise->invalid));
    }

    public function testMagicAccess()
    {
        $promise = new Promise();
        $promise->test = "testing";

        $this->assertTrue(isset($promise->test));
        $this->assertFalse(isset($promise->invalid));
        $this->assertEquals("testing", $promise->test);

        unset($promise->test);
        $this->assertFalse(isset($promise->test));
    }
}

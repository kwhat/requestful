<?php

namespace Requestful\Test\Futures;

use Requestful\Exceptions\CancellationException;
use Requestful\Futures\Promise;
use Requestful\Futures\PromiseInterface;
use Exception;
use LogicException;
use PHPUnit\Framework\TestCase;

class PromiseTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testWait()
    {
        $value = "Test Value";

        $promise = new Promise(
            function () use (&$promise, $value) {
                /** @var Promise $promise */
                $promise->resolve($value);
            }
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $this->assertSame($value, $promise->wait());
        $this->assertEquals(PromiseInterface::FULFILLED, $promise->getState());
    }

    /**
     * @throws Exception
     */
    public function testWaitWithUnwind()
    {
        $value = "Test Value";
        $promise = new Promise(
            function () use (&$promise, $value) {
                $nested = new Promise(
                    function () use (&$nested, $value) {
                        /** @var Promise $nested */
                        $nested->resolve($value);
                    }
                );

                /** @var Promise $promise */
                $promise->resolve($nested);
            }
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $this->assertSame($value, $promise->wait());
        $this->assertEquals(PromiseInterface::FULFILLED, $promise->getState());
    }

    /**
     * @throws Exception
     */
    public function testWaitWithException()
    {
        $value = new Exception("Test Value");
        $promise = new Promise(
            function () use (&$promise, $value) {
                throw $value;
            },
            null
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $this->assertSame($promise->wait(), $value);
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }

    /**
     * @throws Exception
     */
    public function testWaitWithResolvedException()
    {
        $this->expectException(Exception::class);

        $value = new Exception("Test Value");
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
     * @throws Exception
     */
    public function testWaitWithoutCallback()
    {
        $this->expectException(LogicException::class);

        $promise = new Promise(null, null);
        $promise->wait();
    }

    /**
     * @throws Exception
     */
    public function testCancel()
    {
        $isCanceled = false;
        $promise = new Promise(
            null,
            function () use (&$isCanceled) {
                $isCanceled = true;
            }
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $promise->cancel();

        $this->assertInstanceOf(CancellationException::class, $promise->wait());
        $this->assertTrue($isCanceled);
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }

    /**
     * @throws Exception
     */
    public function testCancelWithException()
    {
        $value = new Exception("Test Value");
        $promise = new Promise(
            null,
            function () use (&$promise, $value) {
                throw $value;
            }
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $promise->cancel();
        $this->assertEquals($value, $promise->wait());
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }

    /**
     * @throws Exception
     */
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

    /**
     * @throws Exception
     */
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
                throw new Exception($value);
            }
        );

        $this->assertEquals(PromiseInterface::PENDING, $promise->getState());
        $this->assertInstanceOf(Exception::class, $promise->wait());
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }

    /**
     * @throws Exception
     */
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
        $this->assertInstanceOf(CancellationException::class, $promise->wait());
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
    }

    /**
     * @throws Exception
     */
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
     * @throws Exception
     */
    public function testResolveAgainFailure()
    {
        $this->expectException(LogicException::class);

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
     * @throws Exception
     */
    public function testRejectAgainFailure()
    {
        $this->expectException(LogicException::class);

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
}

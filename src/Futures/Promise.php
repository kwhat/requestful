<?php

declare(strict_types=1);

namespace Requestful\Futures;

use ArrayAccess;
use BadMethodCallException;
use Exception;
use Iterator;
use LogicException;
use Requestful\Exceptions\CancellationException;

/**
 * Promises/A+ implementation.
 *
 * @link https://promisesaplus.com/
 */
class Promise implements ArrayAccess, PromiseInterface, Iterator
{
    /** @var string $state */
    protected $state = self::PENDING;

    /** @var callable $onWait */
    protected $onWait;

    /** @var callable $onCancel */
    protected $onCancel;

    /** @var array $properties */
    protected $properties = [];

    /** @var mixed $result */
    protected $result;

    /**
     * @param callable $onWait Fn that when invoked resolves the promise.
     * @param callable $onCancel Fn that when invoked cancels the promise.
     */
    public function __construct(callable $onWait = null, callable $onCancel = null)
    {
        $this->onWait = $onWait;
        $this->onCancel = $onCancel;
    }

    /**
     * @inheritDoc
     */
    public function cancel()
    {
        if ($this->state == self::PENDING && $this->onCancel != null) {
            try {
                call_user_func($this->onCancel);
                $this->reject(new CancellationException("Promise has been cancelled!"));
            } catch (Exception $e) {
                $this->reject($e);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @inheritDoc
     */
    public function resolve($value)
    {
        $this->settle(self::FULFILLED, $value);
    }

    /**
     * @param mixed $value
     * @throws LogicException if the promise is already resolved.
     */
    public function reject($value)
    {
        $this->settle(self::REJECTED, $value);
    }

    /**
     * @param string $state
     * @param mixed $value
     */
    private function settle($state, $value)
    {
        if ($this->state == $state && $this->result !== $value) {
            throw new LogicException("The promise is already {$state}!");
        } elseif ($this->state != self::PENDING) {
            throw new LogicException("Cannot change a {$this->state} promise to {$state}!");
        }

        $this->state = $state;
        $this->result = $value;
    }

    /**
     * @param callable|null $onFulfilled
     * @param callable|null $onRejected
     *
     * @return PromiseInterface
     */
    public function then(callable $onFulfilled = null, callable $onRejected = null)
    {
        $promise = $this;
        if ($onFulfilled != null || $onRejected != null) {
            $promise = new static(
                function () use (&$promise, $onFulfilled, $onRejected) {
                    try {
                        $value = $this->wait();

                        if ($this->getState() == self::FULFILLED && $onFulfilled != null) {
                            $promise->resolve($onFulfilled($value));
                        } elseif ($this->getState() == self::REJECTED && $onRejected != null) {
                            $promise->reject($onRejected($value));
                        }
                    } catch (Exception $e) {
                        $promise->reject($e);
                    }
                },
                function () {
                    $this->cancel();
                }
            );
        }

        return $promise;
    }

    /**
     * @inheritDoc
     */
    public function wait()
    {
        if ($this->state == self::PENDING) {
            if ($this->onWait == null) {
                throw new BadMethodCallException("Cannot wait on a promise that has no internal wait function.");
            }

            try {
                call_user_func($this->onWait);
            } catch (Exception $e) {
                if ($this->state != self::PENDING) {
                    // The promise was already resolved, so there's a problem in
                    // the application.
                    throw new LogicException($e->getMessage(), $e->getCode(), $e);
                }

                // The promise has not been resolved yet, so reject the promise
                // with the exception.
                $this->reject($e);
            }
        }

        // Unroll nested promises...
        while ($this->result instanceof PromiseInterface) {
            $this->result = $this->result->wait();
        }

        return $this->result;
    }

    /**
     * @inheritDoc
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->properties);
    }

    /**
     * @inheritDoc
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->properties[$offset];
    }

    /**
     * @inheritDoc
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value)
    {
        $this->properties[$offset] = $value;
    }

    /**
     * @inheritDoc
     * @param mixed $offset
     */
    public function offsetUnset($offset)
    {
        unset($this->properties[$offset]);
    }

    /**
     * @return mixed
     */
    public function current()
    {
        return current($this->properties);
    }

    /**
     * @return mixed
     */
    public function next()
    {
        return next($this->properties);
    }

    /**
     * @return string|float|int|bool|null
     */
    public function key()
    {
        return key($this->properties);
    }

    /**
     * @inheritDoc
     * @return bool
     */
    public function valid(): bool
    {
        return $this->offsetExists($this->key());
    }

    /**
     * @inheritDoc
     */
    public function rewind()
    {
        reset($this->properties);
    }

    /**
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return $this->offsetExists($name);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->offsetGet($name);
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function __set(string $name, $value)
    {
        $this->offsetSet($name, $value);
    }

    /**
     * @param string $name
     */
    public function __unset(string $name)
    {
        $this->offsetUnset($name);
    }
}

<?php

declare(strict_types=1);

namespace Requestful\Futures;

use ArrayAccess;
use InvalidArgumentException;
use Iterator;
use Requestful\Exceptions\PromiseException;
use Throwable;

/**
 * Promises/A+ implementation.
 *
 * @link https://promisesaplus.com/
 */
class Promise implements ArrayAccess, PromiseInterface, Iterator
{
    /** @var string $state */
    protected $state = self::PENDING;

    /** @var callable|null $fnWait */
    protected $fnWait;

    /** @var callable|null $fnCancel */
    protected $fnCancel;

    /** @var array $properties */
    protected $properties = [];

    /** @var mixed $result */
    protected $result;

    /**
     * @param callable|null $fnWait Fn that when invoked resolves the promise.
     * @param callable|null $fnCancel Fn that when invoked cancels the promise.
     */
    public function __construct(?callable $fnWait = null, ?callable $fnCancel = null)
    {
        $this->fnWait = $fnWait;
        $this->fnCancel = $fnCancel;
    }

    /**
     * @throws PromiseException
     */
    public function cancel()
    {
        if ($this->state == self::PENDING && $this->fnCancel != null) {
            try {
                call_user_func($this->fnCancel);
                throw new PromiseException("Promise was cancelled");
            } catch (Throwable $e) {
                $this->reject($e);
            }
        }
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @param mixed $value
     * @throws PromiseException
     */
    public function resolve($value)
    {
        $this->settle(self::FULFILLED, $value);
    }

    /**
     * @param mixed $value
     * @throws PromiseException
     */
    public function reject($value)
    {
        $this->settle(self::REJECTED, $value);
    }

    /**
     * @param string $state
     * @param mixed $value
     * @throws PromiseException
     */
    protected function settle($state, $value)
    {
        if ($this->state == $state && $this->result !== $value) {
            throw new PromiseException("The promise is already {$state}!");
        } elseif ($this->state != self::PENDING) {
            throw new PromiseException("Cannot change a {$this->state} promise to {$state}!");
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
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        if ($onFulfilled == null && $onRejected == null) {
            throw new InvalidArgumentException("Both arguments to then cannot be null");
        }

        /** @var static $promise */
        $promise = new static(
            function () use (&$promise, $onFulfilled, $onRejected) {
                try {
                    $value = $this->wait();

                    if ($this->getState() == self::FULFILLED) {
                        if ($onFulfilled != null) {
                            $value = $onFulfilled($value);
                        }

                        $promise->resolve($value);
                    } elseif ($this->getState() == self::REJECTED) {
                        if ($onRejected != null) {
                            $value = $onRejected($value);
                        }

                        $promise->reject($value);
                    }
                } catch (Throwable $e) {
                    $promise->reject($e);
                }
            },
            function () {
                $this->cancel();
            }
        );

        return $promise;
    }

    /**
     * @return mixed
     * @throws PromiseException
     */
    public function unwrap()
    {
        $result = $this->wait();

        // Unwrap nested promises
        while ($result instanceof PromiseInterface) {
            $result = $result->wait();
        }

        return $result;
    }

    /**
     * @inheritDoc
     * @return mixed
     * @throws PromiseException
     */
    public function wait()
    {
        if ($this->state == self::PENDING) {
            if ($this->fnWait == null) {
                throw new PromiseException("Cannot wait on a promise that has no internal wait function.");
            }

            try {
                call_user_func($this->fnWait);
                if ($this->state == self::PENDING) {
                    throw new PromiseException("Wait failed to resolve or reject the promise");
                }
            } catch (Throwable $e) {
                $this->reject($e);
            }
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

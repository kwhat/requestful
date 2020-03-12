<?php

namespace Requestful\Futures;

use ArrayAccess;
use BadMethodCallException;
use Iterator;
use Requestful\Exceptions\PromiseException;
use RuntimeException;
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
     * @inheritDoc
     *
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @inheritDoc
     *
     * @param mixed $value
     *
     * @throws RuntimeException
     */
    public function resolve($value)
    {
        $this->settle(self::FULFILLED, $value);
    }

    /**
     * @inheritDoc
     *
     * @param mixed $value
     *
     * @throws RuntimeException
     */
    public function reject($value)
    {
        $this->settle(self::REJECTED, $value);
    }

    /**
     * @inheritDoc
     *
     * @param string $state
     * @param mixed $value
     *
     * @throws RuntimeException
     */
    protected function settle($state, $value)
    {
        if ($this->state == $state && $this->result !== $value) {
            throw new RuntimeException("The promise is already {$state}!");
        } elseif ($this->state != self::PENDING) {
            throw new RuntimeException("Cannot change a {$this->state} promise to {$state}!");
        }

        $this->state = $state;
        $this->result = $value;
    }

    /**
     * @inheritDoc
     *
     * @param callable|null $onFulfilled
     * @param callable|null $onRejected
     *
     * @return PromiseInterface
     * @throws BadMethodCallException
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        if ($onFulfilled == null && $onRejected == null) {
            throw new BadMethodCallException("Both arguments to then cannot be null");
        }

        return new static(
            function (PromiseInterface $promise) use ($onFulfilled, $onRejected) {
                try {
                    // By calling parent->wait(), we implicitly unroll the loop.
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
    }

    /**
     * @inheritDoc
     *
     * @return mixed
     * @throws PromiseException
     */
    public function wait()
    {
        if ($this->state == self::PENDING) {
            if ($this->fnWait == null) {
                throw new PromiseException("Cannot wait on a promise that has no wait callback available");
            }

            try {
                ($this->fnWait)($this);
            } catch (Throwable $e) {
                $this->reject($e);
            }

            if ($this->state == self::PENDING) {
                throw new PromiseException("Wait failed to resolve or reject the promise");
            } elseif ($this->result instanceof PromiseException) {
                throw $this->result;
            } elseif ($this->result instanceof Throwable) {
                throw new PromiseException($this->result->getMessage(), $this->result->getCode(), $this->result);
            }
        }

        return $this->result;
    }

    /**
     * @inheritDoc
     */
    public function cancel()
    {
        if ($this->state == self::PENDING) {
            try {
                if ($this->fnCancel == null) {
                    throw new PromiseException("Cannot cancel a promise that has no callback available");
                }

                ($this->fnCancel)($this);
                if ($this->state == self::PENDING) {
                    throw new PromiseException("Promise cancel callback failed to resolve or reject the promise");
                }
            } catch (Throwable $e) {
                $this->reject($e);
            }
        }
    }

    /**
     * @inheritDoc
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->properties);
    }

    /**
     * @inheritDoc
     *
     * @param mixed $offset
     *
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->properties[$offset];
    }

    /**
     * @inheritDoc
     *
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value)
    {
        $this->properties[$offset] = $value;
    }

    /**
     * @inheritDoc
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset)
    {
        unset($this->properties[$offset]);
    }

    /**
     * @inheritDoc
     *
     * @return mixed
     */
    public function current()
    {
        return current($this->properties);
    }

    /**
     * @inheritDoc
     *
     * @return mixed
     */
    public function next()
    {
        return next($this->properties);
    }

    /**
     * @inheritDoc
     *
     * @return string|float|int|bool|null
     */
    public function key()
    {
        return key($this->properties);
    }

    /**
     * @inheritDoc
     *
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
     *
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return $this->offsetExists($name);
    }

    /**
     * @param string $name
     *
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

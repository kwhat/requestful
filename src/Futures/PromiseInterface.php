<?php

declare(strict_types=1);

namespace Requestful\Futures;

/**
 * A promise represents the eventual result of an asynchronous operation.
 *
 * The primary way of interacting with a promise is through its then method,
 * which registers callbacks to receive either a promise's eventual value or
 * the reason why the promise cannot be fulfilled.
 *
 * @link https://promisesaplus.com/
 */
interface PromiseInterface
{
    const PENDING = "pending";
    const FULFILLED = "fulfilled";
    const REJECTED = "rejected";

    /**
     * Cancels the promise if possible.
     *
     * @link https://github.com/promises-aplus/cancellation-spec/issues/7
     */
    public function cancel();

    /**
     * Get the state of the promise ("pending", "rejected", or "fulfilled").
     *
     * The three states can be checked against the constants defined on
     * PromiseInterface: PENDING, FULFILLED, and REJECTED.
     *
     * @return string
     */
    public function getState(): string;

    /**
     * Reject the promise with the given reason.
     *
     * @param mixed $value
     */
    public function reject($value);

    /**
     * Resolve the promise with the given value.
     *
     * @param mixed $value
     */
    public function resolve($value);

    /**
     * Appends fulfillment and rejection handlers to the promise, and returns
     * a new promise resolving to the return value of the called handler.
     *
     * @param callable|null $onFulfilled Invoked when the promise fulfills.
     * @param callable|null $onRejected Invoked when the promise is rejected.
     *
     * @return PromiseInterface
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface;

    /**
     * Waits until the promise completes if possible.
     *
     * If the promise cannot be waited on, then the promise will be rejected.
     *
     * @return mixed
     */
    public function wait();
}

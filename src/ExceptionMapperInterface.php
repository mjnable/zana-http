<?php

declare(strict_types=1);

namespace Zana\Http;

use Zana\Http\Exception\HttpException;

/**
 * Turns an exception that is not an HttpException into one, for
 * ErrorMiddleware (ADR-025): e.g. a validation failure into a 422 with its
 * field errors, or an application's "not found" into a 404. Register a
 * mapper as a service tagged TAG; mappers are asked in registration order
 * and the first non-null answer is rendered.
 *
 *     $services->singleton(OrderNotFoundMapper::class)->tag(ExceptionMapperInterface::TAG, OrderNotFoundMapper::class);
 */
interface ExceptionMapperInterface
{
    public const string TAG = 'http.exception_mappers';

    /**
     * The HTTP exception to render instead, with $exception as its previous exception, or null to leave it.
     */
    public function map(\Throwable $exception): ?HttpException;
}

<?php

declare(strict_types=1);

namespace Zana\Http\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Zana\Http\ErrorMiddleware;
use Zana\Http\Exception\NotFoundHttpException;
use Zana\Http\ResponseFactory;

/**
 * FE-43: without a logger, a server error still leaves its reason in PHP's
 * error log, on one line, without the trace.
 */
final class ErrorLogFallbackTest extends TestCase
{
    private string $log = '';

    private string|false $previous = false;

    #[Before]
    public function redirectTheErrorLog(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'zana-error-log-');
        $this->previous = ini_set('error_log', $this->log);
    }

    #[After]
    public function restoreTheErrorLog(): void
    {
        ini_set('error_log', $this->previous === false ? '' : $this->previous);
        @unlink($this->log);
    }

    public function testServerErrorsGoToTheErrorLogWithoutALogger(): void
    {
        $failure = new \RuntimeException("Mail could not be sent\nto the visitor", 0, new \LogicException('Expected response code "250" but got code "550"'));

        $response = $this->errors()->render($failure, new ServerRequest('POST', '/en/start', ['Accept' => 'text/html']));

        $this->assertSame(500, $response->getStatusCode());
        $lines = file($this->log, FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(1, $lines, 'one line, even when a message has line breaks');
        $this->assertStringContainsString('[zana] POST /en/start failed: RuntimeException: Mail could not be sent to the visitor at ', $lines[0]);
        $this->assertStringContainsString('; caused by LogicException: Expected response code "250" but got code "550" at ', $lines[0]);
        $this->assertStringContainsString('ErrorLogFallbackTest.php:', $lines[0], 'the file and line of each exception');
        $this->assertStringNotContainsString('#0 ', $lines[0], 'never the trace');
    }

    public function testClientErrorsAreNotLogged(): void
    {
        $this->errors()->render(new NotFoundHttpException('Gone'), new ServerRequest('GET', '/missing', ['Accept' => 'text/html']));

        $this->assertSame('', (string) file_get_contents($this->log));
    }

    private function errors(): ErrorMiddleware
    {
        return new ErrorMiddleware(new ResponseFactory(new Psr17Factory(), new Psr17Factory()));
    }
}

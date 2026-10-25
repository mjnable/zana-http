<?php

declare(strict_types=1);

namespace Zana\Http;

/**
 * The built-in HTML error page: the status, the detail, and in debug mode
 * the exception with its trace. Error renderers fall back to it.
 */
final class HtmlErrorPage
{
    public static function render(int $status, string $reasonPhrase, ?string $detail = null, ?\Throwable $trace = null): string
    {
        $title = trim("{$status} {$reasonPhrase}");

        $body = '<h1>' . self::escape($title) . '</h1>';
        if ($detail !== null) {
            $body .= '<p>' . self::escape($detail) . '</p>';
        }
        if ($trace !== null) {
            $body .= '<pre>' . self::escape($trace::class . ' in ' . $trace->getFile() . ':' . $trace->getLine() . "\n\n" . $trace->getTraceAsString()) . '</pre>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . self::escape($title) . '</title></head><body>' . $body . '</body></html>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

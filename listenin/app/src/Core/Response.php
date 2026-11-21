<?php
declare(strict_types=1);

namespace ListenIn\Core;

final class Response
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
        | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($data, self::JSON_FLAGS);
        exit;
    }

    public static function error(ApiException $e): never
    {
        $body = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
        if ($e->details) {
            $body['error'] += $e->details;
        }
        self::json($body, $e->status, $e->headers);
    }
}

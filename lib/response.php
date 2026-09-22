<?php
/**
 * Единый формат JSON-ответов и исключения уровня API.
 */

namespace Ghm;

/** Ошибка, которую нужно вернуть клиенту «как есть». */
class ApiException extends \Exception
{
    /** @var int */
    private $status;

    /** @var string */
    private $errorCode;

    /** @var array */
    private $details;

    public function __construct($message, $status = 400, $errorCode = 'bad_request', array $details = array())
    {
        parent::__construct($message);
        $this->status = (int) $status;
        $this->errorCode = (string) $errorCode;
        $this->details = $details;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getDetails()
    {
        return $this->details;
    }
}

final class Response
{
    public static function headers()
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
    }

    public static function ok($data = array(), $status = 200)
    {
        self::send(array('ok' => true, 'data' => $data), $status);
    }

    public static function fail($message, $status = 400, $errorCode = 'error', $detail = null)
    {
        $error = array('message' => (string) $message, 'code' => (string) $errorCode);
        if ($detail !== null && $detail !== '') {
            $error['detail'] = (string) $detail;
        }

        self::send(array('ok' => false, 'error' => $error), $status);
    }

    public static function send(array $payload, $status = 200)
    {
        self::headers();
        if (!headers_sent()) {
            http_response_code((int) $status);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

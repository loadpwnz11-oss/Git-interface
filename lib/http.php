<?php
/**
 * HTTP-клиент на cURL: корректная работа с TLS, повторы, ограничение времени,
 * безопасные редиректы (заголовок авторизации не уходит на сторонние хосты).
 */

namespace Ghm;

class HttpException extends \RuntimeException
{
    /** @var int */
    private $curlCode;

    public function __construct($message, $curlCode = 0)
    {
        parent::__construct($message);
        $this->curlCode = (int) $curlCode;
    }

    public function getCurlCode()
    {
        return $this->curlCode;
    }
}

final class HttpResponse
{
    /** @var int */
    public $status = 0;

    /** @var array */
    public $headers = array();

    /** @var string */
    public $body = '';

    /** @var string */
    public $url = '';

    /** @var array */
    public $meta = array();

    public function header($name)
    {
        $name = strtolower($name);

        return isset($this->headers[$name]) ? $this->headers[$name] : null;
    }

    /** @return array */
    public function json()
    {
        $decoded = json_decode((string) $this->body, true);

        return is_array($decoded) ? $decoded : array();
    }

    public function isOk()
    {
        return $this->status >= 200 && $this->status < 300;
    }
}

final class HttpClient
{
    /** @var array */
    private $options;

    public function __construct(array $options = array())
    {
        $this->options = array_merge(array(
            'base_url'      => Config::get('api_base'),
            'token'         => null,
            'user_agent'    => Config::get('user_agent'),
            'timeout'       => (int) Config::get('api_timeout'),
            'connect_timeout' => 15,
            'retries'       => (int) Config::get('retries'),
            'insecure'      => (bool) Config::get('insecure_tls'),
            'ca_bundle'     => Config::caBundle(),
            'headers'       => array(),
            'secrets'       => array(),
        ), $options);

        if ($this->options['token']) {
            $this->options['secrets'][] = $this->options['token'];
        }
    }

    public static function available()
    {
        return function_exists('curl_init');
    }

    protected function url($path)
    {
        if (preg_match('#^https?://#i', (string) $path)) {
            return (string) $path;
        }

        return rtrim($this->options['base_url'], '/') . '/' . ltrim((string) $path, '/');
    }

    /** @return HttpResponse */
    public function get($path, array $options = array())
    {
        return $this->request('GET', $path, $options);
    }

    /** @return HttpResponse */
    public function post($path, array $options = array())
    {
        return $this->request('POST', $path, $options);
    }

    /** @return HttpResponse */
    public function patch($path, array $options = array())
    {
        return $this->request('PATCH', $path, $options);
    }

    /** @return HttpResponse */
    public function delete($path, array $options = array())
    {
        return $this->request('DELETE', $path, $options);
    }

    /** @return HttpResponse */
    public function request($method, $path, array $options = array())
    {
        if (!self::available()) {
            throw new HttpException('Расширение PHP cURL не установлено. Установите php-curl и перезапустите веб-сервер.');
        }

        $options = array_merge(array(
            'json'        => null,
            'body'        => null,
            'headers'     => array(),
            'token'       => $this->options['token'],
            'timeout'     => $this->options['timeout'],
            'redirects'   => 0,
            'retries'     => null,
            'auth'        => true,
        ), $options);

        $method = strtoupper($method);
        $url = $this->url($path);
        $attempt = 0;
        $maxAttempts = 1 + (int) ($options['retries'] === null ? $this->options['retries'] : $options['retries']);
        $lastResponse = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $response = $this->send($method, $url, $options);
            $lastResponse = $response;

            if (!$this->shouldRetry($method, $response, $attempt, $maxAttempts)) {
                break;
            }

            $delay = $this->retryDelay($response, $attempt);
            usleep((int) ($delay * 1000000));
        }

        return $lastResponse;
    }

    private function shouldRetry($method, HttpResponse $response, $attempt, $maxAttempts)
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        $status = (int) $response->status;
        $networkError = isset($response->meta['error']) && $response->meta['error'] !== '';

        if ($networkError && in_array($method, array('GET', 'HEAD'), true)) {
            return true;
        }
        if ($status === 429 || $status === 502 || $status === 503 || $status === 504) {
            return true;
        }

        return false;
    }

    private function retryDelay(HttpResponse $response, $attempt)
    {
        $after = $response->header('retry-after');
        if ($after !== null && is_numeric($after)) {
            return min(30, max(1, (int) $after));
        }

        return min(8, (int) pow(2, $attempt - 1));
    }

    /** Одиночная отправка запроса. */
    private function send($method, $url, array $options)
    {
        $headers = array(
            'Accept: ' . (isset($options['accept']) ? $options['accept'] : 'application/vnd.github+json'),
            'User-Agent: ' . $this->options['user_agent'],
            'X-GitHub-Api-Version: 2022-11-28',
        );

        if ($options['auth'] && $options['token']) {
            $headers[] = 'Authorization: Bearer ' . $options['token'];
        }

        foreach ($this->options['headers'] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        foreach ((array) $options['headers'] as $name => $value) {
            $headers[] = is_int($name) ? (string) $value : $name . ': ' . $value;
        }

        $body = null;
        if ($options['json'] !== null) {
            $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        } elseif ($options['body'] !== null) {
            $body = (string) $options['body'];
        }

        $responseHeaders = array();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $options['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $this->options['connect_timeout']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                if ($name !== '') {
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . $value : $value;
                }
            }

            return strlen($header);
        });

        $this->applyTlsOptions($ch);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        $response = new HttpResponse();
        $response->status = $result === false ? 0 : $status;
        $response->headers = $responseHeaders;
        $response->body = $result === false ? '' : (string) $result;
        $response->url = $effectiveUrl !== '' ? $effectiveUrl : $url;
        $response->meta = array(
            'error'     => $error !== '' ? Security::redact($error, $this->options['secrets']) : '',
            'errno'     => $errno,
            'attempt'   => $options['redirects'],
        );

        // Ручная обработка редиректов: заголовок Authorization не должен уходить на другой хост.
        if ($response->status >= 300 && $response->status < 400) {
            $location = $response->header('location');
            if ($location && (int) $options['redirects'] < 5) {
                $nextUrl = $this->absoluteUrl($url, $location);
                $nextOptions = $options;
                $nextOptions['redirects'] = (int) $options['redirects'] + 1;
                $nextOptions['retries'] = 0;
                $nextMethod = $method;
                if (in_array($response->status, array(301, 302, 303), true) && $method !== 'HEAD') {
                    $nextMethod = 'GET';
                    $nextOptions['json'] = null;
                    $nextOptions['body'] = null;
                }
                if (!$this->sameHost($url, $nextUrl)) {
                    // На сторонний хост (например codeload) — без токена.
                    $nextOptions['token'] = null;
                    $nextOptions['auth'] = false;
                }

                return $this->requestNested($nextMethod, $nextUrl, $nextOptions);
            }
        }

        if ($result === false) {
            throw new HttpException(
                'Не удалось подключиться к ' . $this->safeHost($url) . ': ' . ($error !== '' ? $error : 'неизвестная ошибка сети'),
                $errno
            );
        }

        return $response;
    }

    private function requestNested($method, $url, array $options)
    {
        $response = $this->send($method, $url, $options);

        return $response;
    }

    private function sameHost($a, $b)
    {
        $hostA = parse_url($a, PHP_URL_HOST);
        $hostB = parse_url($b, PHP_URL_HOST);

        return $hostA && $hostB && strcasecmp($hostA, $hostB) === 0;
    }

    private function absoluteUrl($base, $location)
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return $location;
        }

        $root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $root . ($location !== '' && $location[0] === '/' ? $location : '/' . $location);
    }

    private function safeHost($url)
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? $host : 'серверу';
    }

    public function applyTlsOptions($ch)
    {
        if ($this->options['insecure']) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            return;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $bundle = $this->options['ca_bundle'];
        if ($bundle && is_readable($bundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $bundle);
        }
    }

    /**
     * Скачивание файла по URL (tarball GitHub) с ручными редиректами.
     *
     * @return array{status:int,size:int,content_type:string,url:string}
     */
    public function download($path, $sinkPath, array $options = array())
    {
        $options = array_merge(array(
            'token'     => $this->options['token'],
            'timeout'   => (int) Config::get('archive_timeout'),
            'redirects' => 0,
            'headers'   => array(),
        ), $options);

        $url = $this->url($path);
        $responseHeaders = array();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $options['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $this->options['connect_timeout']);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($header);
        });

        $headers = array(
            'Accept: application/vnd.github+json',
            'User-Agent: ' . $this->options['user_agent'],
            'X-GitHub-Api-Version: 2022-11-28',
        );
        if ($options['token']) {
            $headers[] = 'Authorization: Bearer ' . $options['token'];
        }
        foreach ((array) $options['headers'] as $name => $value) {
            $headers[] = is_int($name) ? (string) $value : $name . ': ' . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $this->applyTlsOptions($ch);

        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        if ($result === false) {
            throw new HttpException('Ошибка скачивания архива: ' . $error, $errno);
        }

        if ($status >= 300 && $status < 400 && !empty($responseHeaders['location'])) {
            if ((int) $options['redirects'] >= 5) {
                throw new HttpException('Слишком много перенаправлений при скачивании архива.');
            }
            $next = $this->absoluteUrl($url, $responseHeaders['location']);
            $nextOptions = $options;
            $nextOptions['redirects'] = (int) $options['redirects'] + 1;
            if (!$this->sameHost($url, $next)) {
                $nextOptions['token'] = null;
            }

            return $this->download($next, $sinkPath, $nextOptions);
        }

        if ($status < 200 || $status >= 300) {
            return array(
                'status'       => $status,
                'size'         => 0,
                'content_type' => $contentType,
                'url'          => $url,
                'body'         => (string) $result,
            );
        }

        $written = @file_put_contents($sinkPath, (string) $result);
        if ($written === false) {
            throw new HttpException('Не удалось записать архив в ' . $sinkPath);
        }

        return array(
            'status'       => $status,
            'size'         => (int) $written,
            'content_type' => $contentType,
            'url'          => $url,
            'body'         => '',
        );
    }
}

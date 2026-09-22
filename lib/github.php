<?php
/**
 * Обёртка над GitHub REST API.
 */

namespace Ghm;

class GitHubException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var array */
    private $payload;

    /** @var string */
    private $userMessage;

    public function __construct($message, $status = 0, array $payload = array(), $userMessage = '')
    {
        parent::__construct($message);
        $this->status = (int) $status;
        $this->payload = $payload;
        $this->userMessage = $userMessage !== '' ? $userMessage : $message;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getPayload()
    {
        return $this->payload;
    }

    public function getUserMessage()
    {
        return $this->userMessage;
    }

    public function isNotFound()
    {
        return $this->status === 404;
    }

    public function isUnauthorized()
    {
        return $this->status === 401;
    }

    public function isRateLimited()
    {
        $message = isset($this->payload['message']) ? (string) $this->payload['message'] : '';

        return $this->status === 429
            || ($this->status === 403 && stripos($message, 'rate limit') !== false);
    }

    public function isAlreadyExists()
    {
        $message = isset($this->payload['message']) ? (string) $this->payload['message'] : '';
        $message = $message . ' ' . json_encode(isset($this->payload['errors']) ? $this->payload['errors'] : array());

        return $this->status === 422 && stripos($message, 'already exists') !== false;
    }

    public function getResetAt()
    {
        foreach (array('x-ratelimit-reset', 'retry-after') as $key) {
            if (!empty($this->payload['headers'][$key])) {
                return (int) $this->payload['headers'][$key];
            }
        }

        return null;
    }
}

final class GitHub
{
    /** @var HttpClient */
    private $http;

    /** @var string|null */
    private $token;

    public function __construct($token = null, ?HttpClient $http = null)
    {
        $this->token = $token !== null && $token !== '' ? (string) $token : null;
        $this->http = $http ? $http : new HttpClient(array('token' => $this->token));
    }

    public function hasToken()
    {
        return $this->token !== null;
    }

    /**
     * Проверка токена: GET /user.
     *
     * @return array Профиль пользователя.
     */
    public function currentUser()
    {
        return $this->get('/user');
    }

    /**
     * Список репозиториев владельца.
     *
     * @return array{items:array,page:int,has_more:bool}
     */
    public function listRepos($login, $page = 1, $perPage = 30, $sort = 'updated')
    {
        $path = sprintf('/user/repos?affiliation=owner&sort=%s&direction=desc&per_page=%d&page=%d',
            rawurlencode($sort), max(1, (int) $perPage), max(1, (int) $page));

        $response = $this->request('GET', $path);
        $items = $this->decodeList($response);

        return array(
            'items'    => $items,
            'page'     => max(1, (int) $page),
            'has_more' => $this->hasNextPage($response),
        );
    }

    /**
     * Информация о репозитории.
     *
     * @return array
     */
    public function getRepo($owner, $repo)
    {
        return $this->get(sprintf('/repos/%s/%s', rawurlencode($owner), rawurlencode($repo)));
    }

    /** @return array|null null, если репозитория нет или он недоступен. */
    public function findRepo($owner, $repo)
    {
        try {
            return $this->getRepo($owner, $repo);
        } catch (GitHubException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Ветки репозитория (с пагинацией).
     *
     * @return array{items:array,page:int,has_more:bool}
     */
    public function listBranches($owner, $repo, $page = 1, $perPage = 100)
    {
        $path = sprintf('/repos/%s/%s/branches?per_page=%d&page=%d',
            rawurlencode($owner), rawurlencode($repo), max(1, (int) $perPage), max(1, (int) $page));

        $response = $this->request('GET', $path);

        return array(
            'items'    => $this->decodeList($response),
            'page'     => max(1, (int) $page),
            'has_more' => $this->hasNextPage($response),
        );
    }

    /**
     * Создание репозитория в личном аккаунте.
     *
     * @return array
     */
    public function createRepo(array $payload)
    {
        $payload = array_merge(array(
            'name'        => '',
            'description' => '',
            'private'     => false,
            'auto_init'   => false,
            'has_issues'  => true,
            'has_wiki'    => false,
        ), $payload);

        return $this->request('POST', '/user/repos', array('json' => $payload))->json();
    }

    /** @return array */
    public function updateRepo($owner, $repo, array $payload)
    {
        return $this->request('PATCH', sprintf('/repos/%s/%s', rawurlencode($owner), rawurlencode($repo)), array(
            'json' => $payload,
        ))->json();
    }

    public function deleteRepo($owner, $repo)
    {
        $this->request('DELETE', sprintf('/repos/%s/%s', rawurlencode($owner), rawurlencode($repo)));
    }

    /** @return array */
    public function createFork($owner, $repo, $name = null)
    {
        $payload = array();
        if ($name !== null && $name !== '') {
            $payload['name'] = (string) $name;
        }
        $payload['default_branch_only'] = false;

        $response = $this->request('POST', sprintf('/repos/%s/%s/forks', rawurlencode($owner), rawurlencode($repo)), array(
            'json' => $payload,
            'timeout' => 120,
        ));

        return $response->json();
    }

    /**
     * Лимиты API текущего токена.
     *
     * @return array{limit:int,remaining:int,reset:int}
     */
    public function rateLimit()
    {
        $data = $this->get('/rate_limit');
        $core = isset($data['resources']['core']) ? $data['resources']['core'] : array();

        return array(
            'limit'     => isset($core['limit']) ? (int) $core['limit'] : 0,
            'remaining' => isset($core['remaining']) ? (int) $core['remaining'] : 0,
            'reset'     => isset($core['reset']) ? (int) $core['reset'] : 0,
        );
    }

    /**
     * Скачивание tarball ветки.
     *
     * @return array{status:int,size:int,content_type:string,url:string}
     */
    public function downloadTarball($owner, $repo, $ref, $sinkPath)
    {
        $api = sprintf('/repos/%s/%s/tarball/%s', rawurlencode($owner), rawurlencode($repo), rawurlencode($ref));

        return $this->http->download($api, $sinkPath);
    }

    /**
     * Полный список репозиториев с прозрачной пагинацией.
     *
     * @return array<int,array>
     */
    public function listAllRepos($login, $maxPages = 10, $perPage = 100, $sort = 'updated')
    {
        $items = array();
        for ($page = 1; $page <= $maxPages; $page++) {
            $chunk = $this->listRepos($login, $page, $perPage, $sort);
            $items = array_merge($items, $chunk['items']);
            if (!$chunk['has_more']) {
                break;
            }
        }

        return $items;
    }

    /** @return array */
    private function get($path)
    {
        return $this->request('GET', $path)->json();
    }

    /** @return HttpResponse */
    private function request($method, $path, array $options = array())
    {
        try {
            $response = $this->http->request($method, $path, $options);
        } catch (HttpException $e) {
            throw new GitHubException(
                $e->getMessage(),
                0,
                array('message' => $e->getMessage()),
                'Нет связи с GitHub API: ' . $e->getMessage()
            );
        }

        if ($response->status >= 200 && $response->status < 300) {
            return $response;
        }

        $payload = $response->json();
        if (!isset($payload['message']) || $payload['message'] === '') {
            $payload['message'] = 'HTTP ' . $response->status;
        }
        $payload['headers'] = $response->headers;

        throw new GitHubException(
            $payload['message'],
            $response->status,
            $payload,
            self::friendlyMessage($response->status, $payload)
        );
    }

    private static function friendlyMessage($status, array $payload)
    {
        $message = isset($payload['message']) ? (string) $payload['message'] : '';
        $headers = isset($payload['headers']) ? $payload['headers'] : array();

        switch ((int) $status) {
            case 401:
                return 'Неверный или просроченный токен доступа GitHub. Проверьте токен и разрешения (scope «repo»).';
            case 403:
                if (stripos($message, 'rate limit') !== false) {
                    $reset = !empty($headers['x-ratelimit-reset']) ? (int) $headers['x-ratelimit-reset'] : null;
                    $when = $reset ? ' Лимит обновится в ' . date('H:i', $reset) . '.' : '';

                    return 'Исчерпан лимит запросов к GitHub API.' . $when;
                }
                if (stripos($message, 'secondary rate') !== false) {
                    return 'GitHub временно ограничил запросы (secondary rate limit). Попробуйте через минуту.';
                }
                if (stripos($message, 'must have admin') !== false || stripos($message, 'delete_repo') !== false
                    || stripos($message, 'two-factor') !== false) {
                    return 'Недостаточно прав токена для этой операции. Нужен scope «delete_repo» (для удаления) или «repo».';
                }

                return 'Доступ запрещён: ' . $message;
            case 404:
                return 'Репозиторий не найден или у токена нет к нему доступа.';
            case 409:
                return 'Репозиторий пуст — GitHub не может выполнить операцию для пустого репозитория.';
            case 422:
                if (stripos($message, 'already exists') !== false) {
                    return 'Репозиторий с таким именем уже существует в вашем аккаунте.';
                }

                return 'GitHub отклонил запрос: ' . $message;
            case 451:
                return 'Доступ к репозиторию заблокирован по юридическим причинам (DMCA).';
            default:
                if ((int) $status >= 500) {
                    return 'GitHub временно недоступен (HTTP ' . $status . '). Попробуйте позже.';
                }

                return 'Ошибка GitHub API (HTTP ' . $status . '): ' . $message;
        }
    }

    /** @return array */
    private function decodeList(HttpResponse $response)
    {
        $data = json_decode((string) $response->body, true);

        return is_array($data) ? $data : array();
    }

    private function hasNextPage(HttpResponse $response)
    {
        $link = $response->header('link');
        if ($link && strpos($link, 'rel="next"') !== false) {
            return true;
        }

        return false;
    }
}

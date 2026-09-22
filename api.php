<?php
/**
 * JSON API приложения. Все действия выполняются на сервере,
 * токен GitHub хранится только в сессии и никогда не отдаётся в браузер.
 */

require_once __DIR__ . '/lib/bootstrap.php';

use Ghm\ApiException;
use Ghm\App;
use Ghm\Config;
use Ghm\Copier;
use Ghm\Format;
use Ghm\GitHubException;
use Ghm\JobStore;
use Ghm\RepoView;
use Ghm\Response;
use Ghm\Security;

$app = App::boot();
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

/** Тело запроса (JSON или form-urlencoded). */
function requestBody()
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }

    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        $body = $decoded;

        return $body;
    }

    $body = is_array($_POST) ? $_POST : array();

    return $body;
}

function input($key, $default = null)
{
    $body = requestBody();

    return array_key_exists($key, $body) ? $body[$key] : (isset($_GET[$key]) ? $_GET[$key] : $default);
}

function boolInput($key, $default = false)
{
    $value = input($key, $default);
    if (is_bool($value)) {
        return $value;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function requireOwnerRepo()
{
    $owner = trim((string) input('owner', ''));
    $repo = trim((string) input('repo', ''));

    if (!Security::isValidOwner($owner)) {
        throw new ApiException('Некорректный владелец репозитория.', 422, 'bad_owner');
    }
    if (!Security::isValidRepoName($repo)) {
        throw new ApiException('Некорректное имя репозитория.', 422, 'bad_repo');
    }

    return array($owner, $repo);
}

/** Постраничный список с фильтрами для вкладок «Репозитории» и «Мои копии». */
function repoList($app, array $params)
{
    $login = (string) $app->login();
    $page = max(1, (int) (isset($params['page']) ? $params['page'] : 1));
    $perPage = (int) (isset($params['per_page']) ? $params['per_page'] : 30);
    $perPage = max(1, min(100, $perPage));
    $query = mb_strtolower(trim((string) (isset($params['q']) ? $params['q'] : '')), 'UTF-8');
    $filter = (string) (isset($params['filter']) ? $params['filter'] : 'all');
    $sort = (string) (isset($params['sort']) ? $params['sort'] : 'updated');
    $loadAll = !empty($params['all']);

    $history = $app->history()->namesMap($login);
    $items = array();
    $hasMore = false;

    if ($loadAll) {
        $repos = $app->github()->listAllRepos($login, 5, 100, $sort);
    } else {
        $chunk = $app->github()->listRepos($login, $page, $perPage, $sort);
        $repos = $chunk['items'];
        $hasMore = $chunk['has_more'];
    }

    foreach ($repos as $repo) {
        if (!is_array($repo)) {
            continue;
        }
        $view = RepoView::fromApi($repo);
        $key = strtolower($view['full_name']);
        if (isset($history[$key])) {
            $view['is_copy'] = true;
            $view['copy_mode'] = isset($history[$key]['mode']) ? $history[$key]['mode'] : Copier::MODE_COPY;
        } else {
            $view['is_copy'] = false;
            $view['copy_mode'] = '';
        }

        if ($filter === 'private' && !$view['private']) {
            continue;
        }
        if ($filter === 'public' && $view['private']) {
            continue;
        }
        if ($filter === 'fork' && !$view['fork']) {
            continue;
        }
        if ($filter === 'copy' && !$view['is_copy'] && !$view['fork']) {
            continue;
        }
        if ($filter === 'source' && ($view['fork'] || $view['is_copy'])) {
            continue;
        }
        if ($query !== '') {
            $haystack = mb_strtolower($view['name'] . ' ' . $view['description'] . ' ' . $view['language'], 'UTF-8');
            if (mb_strpos($haystack, $query) === false) {
                continue;
            }
        }

        $items[] = $view;
    }

    return array(
        'items'    => $items,
        'page'     => $page,
        'per_page' => $perPage,
        'has_more' => $hasMore,
        'total'    => $loadAll ? count($items) : null,
        'login'    => $login,
    );
}

try {
    switch ($action) {
        /* -------------------------------------------------------------- */
        case 'health':
            Response::ok(array(
                'status'  => 'ok',
                'version' => Config::version(),
                'demo'    => Config::isDemo(),
                'git'     => $app->git()->available(),
            ));
            break;

        /* -------------------------------------------------------------- */
        case 'session':
            $user = $app->user();
            Response::ok(array(
                'authenticated' => $app->isAuthorized(),
                'user'          => $user,
                'csrf'          => Security::csrfToken(),
                'demo'          => Config::isDemo(),
                'version'       => Config::version(),
                'modes'         => Copier::modes(),
                'steps'         => array(
                    Copier::MODE_COPY    => Copier::stepsFor(Copier::MODE_COPY),
                    Copier::MODE_ARCHIVE => Copier::stepsFor(Copier::MODE_ARCHIVE),
                    Copier::MODE_FORK    => Copier::stepsFor(Copier::MODE_FORK),
                ),
            ));
            break;

        /* -------------------------------------------------------------- */
        case 'login':
            if ($method !== 'POST') {
                throw new ApiException('Метод не поддерживается.', 405, 'method_not_allowed');
            }
            Security::startSession();
            $token = trim((string) input('token', ''));
            $username = trim((string) input('username', ''));

            if ($token === '') {
                throw new ApiException('Введите токен доступа GitHub.', 422, 'token_required');
            }

            try {
                $user = (new Ghm\GitHub($token))->currentUser();
            } catch (GitHubException $e) {
                throw new ApiException($e->getUserMessage(), $e->getStatus() === 401 ? 401 : 502, 'login_failed', $e->getMessage());
            }

            if (!empty($user['login'])) {
                if ($username !== '' && strcasecmp($username, (string) $user['login']) !== 0) {
                    throw new ApiException(
                        'Логин «' . $username . '» не совпадает с владельцем токена («' . $user['login'] . '»). Мы используем логин из токена.',
                        422,
                        'login_mismatch'
                    );
                }
            } else {
                throw new ApiException('GitHub не вернул профиль пользователя. Проверьте токен.', 502, 'login_failed');
            }

            $profile = Security::login_user($token, $user);
            Response::ok(array(
                'authenticated' => true,
                'user'          => $profile,
                'csrf'          => Security::csrfToken(),
                'modes'         => Copier::modes(),
            ));
            break;

        /* -------------------------------------------------------------- */
        case 'logout':
            if ($method !== 'POST') {
                throw new ApiException('Метод не поддерживается.', 405, 'method_not_allowed');
            }
            Security::logout();
            Response::ok(array('authenticated' => false, 'csrf' => Security::csrfToken()));
            break;

        /* -------------------------------------------------------------- */
        case 'repos':
            $app->requireAuth();
            Response::ok(repoList($app, $_GET));
            break;

        /* -------------------------------------------------------------- */
        case 'copies':
            $app->requireAuth();
            $login = (string) $app->login();
            $history = $app->history()->all($login);
            $seen = array();
            $copies = array();

            foreach ($history as $entry) {
                $key = isset($entry['full_name']) ? strtolower((string) $entry['full_name']) : '';
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $copies[] = array(
                    'full_name'      => (string) $entry['full_name'],
                    'html_url'       => (string) $entry['html_url'],
                    'source'         => (string) $entry['source'],
                    'source_url'     => (string) $entry['source_url'],
                    'mode'           => (string) $entry['mode'],
                    'private'        => !empty($entry['private']),
                    'default_branch' => (string) $entry['default_branch'],
                    'branches'       => (int) $entry['branches'],
                    'commits'        => (int) $entry['commits'],
                    'size_human'     => (string) $entry['size_human'],
                    'created_at'     => (int) $entry['created_at'],
                    'created_human'  => Format::dateTime((int) $entry['created_at']),
                    'origin'         => 'app',
                );
            }

            // Форки, созданные вне приложения, тоже показываем.
            $forksTotal = 0;
            try {
                foreach ($app->github()->listAllRepos($login, 5, 100, 'updated') as $repo) {
                    if (empty($repo['fork'])) {
                        continue;
                    }
                    $forksTotal++;
                    $view = RepoView::fromApi($repo);
                    $key = strtolower($view['full_name']);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $copies[] = array(
                        'full_name'      => $view['full_name'],
                        'html_url'       => $view['html_url'],
                        'source'         => $view['fork_parent'] ? $view['fork_parent']['full_name'] : '',
                        'source_url'     => $view['fork_parent'] ? $view['fork_parent']['html_url'] : '',
                        'mode'           => Copier::MODE_FORK,
                        'private'        => $view['private'],
                        'default_branch' => $view['default_branch'],
                        'branches'       => 0,
                        'commits'        => 0,
                        'size_human'     => $view['size_human'],
                        'created_at'     => $view['created_at'],
                        'created_human'  => Format::dateTime($view['created_at']),
                        'origin'         => 'github',
                    );
                }
            } catch (GitHubException $e) {
                // Список форков не критичен — историю всё равно отдаём.
            }

            Response::ok(array('items' => $copies, 'forks_total' => $forksTotal, 'login' => $login));
            break;

        /* -------------------------------------------------------------- */
        case 'repo':
            $app->requireAuth();
            list($owner, $repo) = requireOwnerRepo();
            $data = $app->github()->getRepo($owner, $repo);
            $view = RepoView::fromApi($data);
            $entry = $app->history()->find((string) $app->login(), $view['full_name']);
            $view['is_copy'] = $entry !== null;
            Response::ok($view);
            break;

        /* -------------------------------------------------------------- */
        case 'branches':
            $app->requireAuth();
            list($owner, $repo) = requireOwnerRepo();

            $repoData = $app->github()->getRepo($owner, $repo);
            $defaultBranch = isset($repoData['default_branch']) ? (string) $repoData['default_branch'] : '';
            $page = max(1, (int) input('page', 1));

            $chunk = $app->github()->listBranches($owner, $repo, $page, 100);
            $branches = array();
            foreach ($chunk['items'] as $branch) {
                if (is_array($branch)) {
                    $branches[] = RepoView::branch($branch, $defaultBranch);
                }
            }

            Response::ok(array(
                'owner'          => $owner,
                'repo'           => $repo,
                'default_branch' => $defaultBranch,
                'html_url'       => isset($repoData['html_url']) ? (string) $repoData['html_url'] : '',
                'branches'       => $branches,
                'page'           => $page,
                'has_more'       => $chunk['has_more'],
                'count'          => count($branches),
            ));
            break;

        /* -------------------------------------------------------------- */
        case 'copy_start':
            $app->requireAuth();
            $app->requireCsrf();

            $job = $app->copier()->start(session_id(), array(
                'source_owner' => input('source_owner', ''),
                'source_repo'  => input('source_repo', ''),
                'target_name'  => input('target_name', ''),
                'private'      => boolInput('private', true),
                'overwrite'    => boolInput('overwrite', false),
                'mode'         => input('mode', Copier::MODE_COPY),
            ));

            Response::ok(array('job' => $job));
            break;

        /* -------------------------------------------------------------- */
        case 'copy_step':
            $app->requireAuth();
            $app->requireCsrf();
            ignore_user_abort(true);
            @set_time_limit(0);

            $job = $app->copier()->execute(
                session_id(),
                (string) input('job_id', ''),
                (string) input('step', '')
            );

            Response::ok(array('job' => $job));
            break;

        /* -------------------------------------------------------------- */
        case 'job':
            $app->requireAuth();
            $job = $app->copier()->job(session_id(), (string) input('job_id', ''));
            Response::ok(array('job' => $job));
            break;

        /* -------------------------------------------------------------- */
        case 'copy_cancel':
            $app->requireAuth();
            $app->requireCsrf();
            $job = $app->copier()->cancel(session_id(), (string) input('job_id', ''));
            Response::ok(array('job' => $job));
            break;

        /* -------------------------------------------------------------- */
        case 'delete_repo':
            $app->requireAuth();
            $app->requireCsrf();

            $owner = trim((string) input('owner', ''));
            $repo = trim((string) input('repo', ''));
            $confirm = trim((string) input('confirm', ''));

            if (!Security::isValidOwner($owner) || !Security::isValidRepoName($repo)) {
                throw new ApiException('Некорректные имя владельца или репозитория.', 422, 'bad_repo');
            }
            if (strcasecmp($owner, (string) $app->login()) !== 0) {
                throw new ApiException('Удалять можно только свои репозитории.', 403, 'forbidden');
            }
            if ($confirm !== $repo) {
                throw new ApiException('Подтверждение не совпадает с именем репозитория.', 422, 'confirm_mismatch');
            }

            try {
                $app->github()->deleteRepo($owner, $repo);
            } catch (GitHubException $e) {
                if ($e->getStatus() === 403) {
                    throw new ApiException(
                        'GitHub не разрешил удаление: у токена нет scope «delete_repo». Добавьте его в настройках токена и войдите снова.',
                        403,
                        'delete_forbidden'
                    );
                }
                throw $e;
            }

            $app->history()->remove((string) $app->login(), $owner . '/' . $repo);
            Response::ok(array('deleted' => $owner . '/' . $repo));
            break;

        /* -------------------------------------------------------------- */
        case 'rate_limit':
            $app->requireAuth();
            Response::ok($app->github()->rateLimit());
            break;

        /* -------------------------------------------------------------- */
        case 'status':
            $app->requireAuth();
            Response::ok($app->diagnostics());
            break;

        /* -------------------------------------------------------------- */
        case 'cleanup':
            $app->requireAuth();
            $app->requireCsrf();
            $removed = $app->jobs()->gc(3600, 7200);
            Response::ok(array('removed_jobs' => $removed));
            break;

        default:
            throw new ApiException('Неизвестное действие: ' . $action, 404, 'unknown_action');
    }
} catch (ApiException $e) {
    Response::fail($e->getMessage(), $e->getStatus(), $e->getErrorCode(), is_array($e->getDetails())
        ? (isset($e->getDetails()['full_name']) ? (string) $e->getDetails()['full_name'] : '')
        : (string) $e->getDetails());
} catch (GitHubException $e) {
    $upstream = (int) $e->getStatus();
    $status = in_array($upstream, array(401, 403, 404, 409, 422, 429), true) ? $upstream : 502;
    Response::fail($e->getUserMessage(), $status, 'github_error', $e->getMessage());
} catch (\Exception $e) {
    error_log('[GitHub Manager] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::fail('Внутренняя ошибка: ' . $e->getMessage(), 500, 'internal_error');
}

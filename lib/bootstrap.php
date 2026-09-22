<?php
/**
 * Инициализация приложения: подключение библиотек, единая обработка ошибок,
 * контекст запроса (клиент GitHub, задание, история).
 */

namespace Ghm;

final class App
{
    /** @var App|null */
    private static $instance = null;

    /** @var GitClient|null */
    private $git = null;

    /** @var GitHub|null */
    private $github = null;

    /** @var History|null */
    private $history = null;

    /** @var JobStore|null */
    private $jobs = null;

    /** @var string */
    const ROOT = __DIR__ . '/..';

    /** Подключение файлов библиотеки. */
    public static function boot()
    {
        require_once __DIR__ . '/security.php';
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/response.php';
        require_once __DIR__ . '/process.php';
        require_once __DIR__ . '/http.php';
        require_once __DIR__ . '/format.php';
        require_once __DIR__ . '/repo_view.php';
        require_once __DIR__ . '/github.php';
        require_once __DIR__ . '/jobs.php';
        require_once __DIR__ . '/git_client.php';
        require_once __DIR__ . '/history.php';
        require_once __DIR__ . '/copier.php';

        return self::get();
    }

    /** @return App */
    public static function get()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        Security::startSession();
        $this->initErrorHandling();
        $this->initDemoSession();
    }

    private function initErrorHandling()
    {
        set_exception_handler(function ($e) {
            if ($e instanceof ApiException) {
                Response::fail($e->getMessage(), $e->getStatus(), $e->getErrorCode(), self::detailToString($e->getDetails()));
            } elseif ($e instanceof GitHubException) {
                $upstream = (int) $e->getStatus();
                $status = in_array($upstream, array(401, 403, 404, 409, 422, 429), true) ? $upstream : 502;
                Response::fail($e->getUserMessage(), $status, 'github_error', $e->getMessage());
            } else {
                error_log('[GitHub Manager] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                Response::fail(
                    'Внутренняя ошибка сервера. Подробности в логе веб-сервера.',
                    500,
                    'internal_error',
                    self::isDebug() ? $e->getMessage() : ''
                );
            }
        });
    }

    public static function isDebug()
    {
        $value = getenv('GHM_DEBUG');

        return $value !== false && $value !== '' && $value !== '0';
    }

    private static function detailToString($detail)
    {
        if (is_array($detail)) {
            return isset($detail['full_name']) ? (string) $detail['full_name'] : '';
        }

        return (string) $detail;
    }

    /** В демо-режиме вход выполняется автоматически на локальный макет GitHub. */
    private function initDemoSession()
    {
        if (!Config::isDemo() || Security::isAuthorized()) {
            return;
        }

        $token = (string) Config::get('demo_token');
        try {
            $user = (new GitHub($token))->currentUser();
        } catch (\Exception $e) {
            $user = array(
                'login'      => (string) Config::get('demo_login'),
                'name'       => 'Демо-пользователь',
                'avatar_url' => '',
                'html_url'   => '',
            );
        }

        Security::login_user($token, $user);
    }

    public function isAuthorized()
    {
        return Security::isAuthorized();
    }

    public function login()
    {
        return Security::login();
    }

    public function user()
    {
        return Security::user();
    }

    public function token()
    {
        return Security::token();
    }

    public function github()
    {
        if ($this->github === null) {
            $this->github = new GitHub(Security::token());
        }

        return $this->github;
    }

    public function git()
    {
        if ($this->git === null) {
            $this->git = new GitClient(Security::token());
        }

        return $this->git;
    }

    public function jobs()
    {
        if ($this->jobs === null) {
            $this->jobs = new JobStore();
        }

        return $this->jobs;
    }

    public function history()
    {
        if ($this->history === null) {
            $this->history = new History();
        }

        return $this->history;
    }

    public function copier()
    {
        return new Copier($this->github(), (string) $this->login(), $this->jobs(), $this->git(), $this->history());
    }

    /** Требовать авторизацию (для API). */
    public function requireAuth()
    {
        if (!$this->isAuthorized()) {
            throw new ApiException('Требуется вход в GitHub. Обновите страницу и войдите заново.', 401, 'unauthorized');
        }
    }

    /** Требовать корректный CSRF-токен. */
    public function requireCsrf()
    {
        $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : null;
        if ($token === null && isset($_POST['csrf'])) {
            $token = $_POST['csrf'];
        }

        if (!Security::csrfValid($token)) {
            throw new ApiException('Сессия устарела. Обновите страницу и повторите действие.', 419, 'csrf_failed');
        }
    }

    /** Диагностика окружения для вкладки «Состояние». */
    public function diagnostics()
    {
        $dataDir = Config::dataDir();
        $gitVersion = $this->git()->version(true);

        $diagnostics = array(
            'app' => array(
                'name'    => Config::appName(),
                'version' => Config::version(),
                'demo'    => Config::isDemo(),
                'debug'   => self::isDebug(),
            ),
            'php' => array(
                'version'    => PHP_VERSION,
                'sapi'       => PHP_SAPI,
                'memory'     => (string) ini_get('memory_limit'),
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'extensions' => array(
                    'curl'      => extension_loaded('curl'),
                    'json'      => extension_loaded('json'),
                    'mbstring'  => extension_loaded('mbstring'),
                    'openssl'   => extension_loaded('openssl'),
                    'zlib'      => extension_loaded('zlib'),
                    'zip'       => class_exists('ZipArchive'),
                ),
                'functions'  => array(
                    'proc_open' => function_exists('proc_open') && !Process::isDisabled('proc_open'),
                    'exec'      => function_exists('exec') && !Process::isDisabled('exec'),
                    'proc_terminate' => function_exists('proc_terminate'),
                ),
            ),
            'git' => array(
                'available' => $gitVersion !== '',
                'version'   => $gitVersion,
                'binary'    => Config::gitBinary(),
            ),
            'network' => array(
                'api_base'    => Config::get('api_base'),
                'git_host'    => Config::get('git_host'),
                'ca_bundle'   => Config::caBundle(),
                'insecure_tls' => (bool) Config::get('insecure_tls'),
            ),
            'storage' => array(
                'data_dir'    => $dataDir,
                'writable'    => is_writable($dataDir),
                'free_space'  => self::freeSpace($dataDir),
            ),
        );

        if ($this->isAuthorized()) {
            try {
                $diagnostics['rate_limit'] = $this->github()->rateLimit();
            } catch (\Exception $e) {
                $diagnostics['rate_limit_error'] = $e->getMessage();
            }
        }

        return $diagnostics;
    }

    private static function freeSpace($dir)
    {
        $free = @disk_free_space($dir);
        if ($free === false) {
            return '';
        }

        return Format::bytes((int) round($free / 1024));
    }
}

App::boot();

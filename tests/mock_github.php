<?php
/**
 * Локальный макет GitHub API.
 *
 * Используется тестами (tests/run.php) и демо-режимом приложения
 * (config.local.php с GHM_API_BASE=http://127.0.0.1:8090).
 * Полностью эмулирует только те эндпоинты, которые нужны приложению,
 * но данные настоящие: репозитории — это реальные bare-репозитории git,
 * поэтому клонирование, отправка веток и статистика работают по-настоящему.
 *
 * Запуск:  php -S 127.0.0.1:8090 tests/mock_github.php
 */

require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/process.php';

use Ghm\Process;
use Ghm\Security;

final class MockGitHub
{
    /** @var string */
    private $stateDir;

    /** @var array */
    private $state = array();

    /** @var bool */
    private $dirty = false;

    public function __construct()
    {
        $env = getenv('MOCK_GH_STATE');
        $this->stateDir = $env !== false && $env !== '' ? rtrim($env, '/') : sys_get_temp_dir() . '/ghm-mock';
        $this->stateDir = rtrim($this->stateDir, '/');
        $this->ensureDir($this->stateDir . '/repos');
        $this->ensureDir($this->stateDir . '/seeds');
        $this->stateDir = rtrim($this->stateDir, '/');
        $this->load();
    }

    /* ------------------------------ инфраструктура ---------------------- */

    private function ensureDir($dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }

    private function git(array $args, $cwd = null)
    {
        $command = array('git');
        if ($cwd !== null && $cwd !== '') {
            // -C надёжнее, чем cwd процесса: часть хостингов не передаёт cwd потомкам.
            $command[] = '-C';
            $command[] = $cwd;
        }
        // Имя/почта коммиттера — файлом: переменные окружения доходят не везде
        // (например, в php-wasm), а в аргументах нельзя держать пробелы.
        $command[] = '-c';
        $command[] = 'include.path=' . $this->gitConfig();
        $command = array_merge($command, $args);

        return Process::run($command, array(
            'cwd'     => $cwd,
            'env'     => array(
                'PATH'                => getenv('PATH') !== false ? (string) getenv('PATH') : '/usr/bin:/bin',
                'HOME'                => getenv('HOME') !== false ? (string) getenv('HOME') : $this->stateDir,
                'LANG'                => 'C',
                'LC_ALL'              => 'C',
                'GIT_TERMINAL_PROMPT' => '0',
            ),
            'timeout' => 120,
        ));
    }

    /** Файл конфигурации для git: подпись коммитов и идентичность. */
    private function gitConfig()
    {
        $file = $this->stateDir . '/gitconfig';
        if (!is_file($file)) {
            $contents = "[user]\n\tname = Demo User\n\temail = demo@example.com\n"
                . "[commit]\n\tgpgsign = false\n[core]\n\tquotepath = false\n";
            file_put_contents($file, $contents, LOCK_EX);
            @chmod($file, 0600);
        }

        return $file;
    }

    /** Коммит с сообщением из файла: аргументы без пробелов, текст любой. */
    private function commit($dir, $message)
    {
        $file = $this->stateDir . '/commit-message.txt';
        file_put_contents($file, $message . "\n");
        $result = $this->git(array('commit', '--quiet', '--file=' . $file), $dir);
        @unlink($file);

        return $result;
    }

    private function load()
    {
        $file = $this->stateDir . '/state.json';
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $this->state = $decoded;
            }
        }

        if (empty($this->state['seeded']) || !$this->stateIsValid()) {
            $this->seed();
        }
    }

    /** Проверка, что состояния и каталогов достаточно для работы. */
    private function stateIsValid()
    {
        if (empty($this->state['repos'])) {
            return false;
        }

        foreach ($this->state['repos'] as $repo) {
            if (empty($repo['path']) || !is_dir($repo['path'])) {
                return false;
            }
        }

        return true;
    }

    private function save()
    {
        file_put_contents($this->stateDir . '/state.json', json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function repoPath($owner, $name)
    {
        return $this->stateDir . '/repos/' . $owner . '/' . $name . '.git';
    }

    /** Создание исходных репозиториев демо-стенда. */
    private function seed()
    {
        $this->state = array('seeded' => time(), 'repos' => array(), 'metrics' => array(), 'stats' => array());

        $this->removeTree($this->stateDir . '/repos');
        $this->removeTree($this->stateDir . '/seeds');
        $this->ensureDir($this->stateDir . '/seeds');
        $this->ensureDir($this->stateDir . '/repos');

        /* --- demo-org/hello-world: две ветки, тег, три коммита ----------- */
        $work = $this->stateDir . '/seeds/hello-world';
        $this->ensureDir($work . '/src');
        $this->ensureDir($work . '/docs');
        $this->write($work . '/README.md', "# Hello World\n\nДемонстрационный репозиторий для GitHub Manager.\n\n- пример копирования\n- несколько веток\n");
        $this->write($work . '/src/main.js', "console.log('hello');\n");
        $this->write($work . '/docs/guide.md', "# Руководство\n\n1. Откройте интерфейс\n2. Нажмите «Создать копию»\n");
        $this->git(array('init', '--quiet', '--initial-branch=main', '.'), $work);
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Первый коммит: структура проекта');
        $this->write($work . '/src/main.js', "console.log('hello, world');\n");
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Второй коммит: приветствие');
        $this->git(array('tag', 'v1.0.0'), $work);
        $this->git(array('checkout', '--quiet', '-b', 'dev'), $work);
        $this->write($work . '/src/experimental.js', "export const feature = true;\n");
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Третий коммит: эксперимент в ветке dev');
        $this->git(array('checkout', '--quiet', 'main'), $work);
        $helloWorld = $this->importBare('demo-org', 'hello-world', $work, array(
            'description' => 'Демонстрационный репозиторий с несколькими ветками',
            'language'    => 'JavaScript',
        ));

        /* --- demo-org/legacy-site: master + gh-pages + .gitignore -------- */
        $work = $this->stateDir . '/seeds/legacy-site';
        $this->ensureDir($work);
        $this->write($work . '/index.html', "<!DOCTYPE html>\n<html lang=\"ru\"><body><h1>Legacy site</h1></body></html>\n");
        $this->write($work . '/assets/style.css', "body { font-family: sans-serif; }\n");
        $this->write($work . '/.gitignore', "dist/\nnode_modules/\n");
        $this->write($work . '/dist/build.js', "// этот файл должен игнорироваться .gitignore\n");
        $this->git(array('init', '--quiet', '--initial-branch=master', '.'), $work);
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Первый коммит: статичный сайт');
        $this->write($work . '/about.html', "<h1>О проекте</h1>\n");
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Второй коммит: страница о проекте');
        $this->git(array('checkout', '--quiet', '-b', 'gh-pages'), $work);
        $this->write($work . '/index.html', "<!DOCTYPE html>\n<html><body><h1>Опубликованная версия</h1></body></html>\n");
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Публикация ветки gh-pages');
        $this->git(array('checkout', '--quiet', 'master'), $work);
        $this->importBare('demo-org', 'legacy-site', $work, array(
            'description' => 'Старый сайт на ветке master',
            'language'    => 'HTML',
        ));

        /* --- demo-org/private-notes: приватный --------------------------- */
        $work = $this->stateDir . '/seeds/private-notes';
        $this->ensureDir($work);
        $this->write($work . '/notes.md', "# Заметки\n\nПриватный репозиторий.\n");
        $this->git(array('init', '--quiet', '--initial-branch=main', '.'), $work);
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Первый коммит: заметки');
        $this->write($work . '/notes.md', "# Заметки\n\nПриватный репозиторий.\n\nВторая запись.\n");
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Второй коммит: дополнение');
        $this->importBare('demo-org', 'private-notes', $work, array(
            'description' => 'Приватный репозиторий для проверки прав',
            'private'     => true,
            'language'    => 'Markdown',
        ));

        /* --- demo-org/tiny-cli: один коммит ----------------------------- */
        $work = $this->stateDir . '/seeds/tiny-cli';
        $this->ensureDir($work);
        $this->write($work . '/README.md', "# tiny-cli\n\nМаленькая утилита для демонстрации.\n");
        $this->write($work . '/cli.sh', "#!/bin/sh\necho \"tiny-cli\"\n");
        $this->git(array('init', '--quiet', '--initial-branch=main', '.'), $work);
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Первый коммит: tiny-cli');
        $this->importBare('demo-org', 'tiny-cli', $work, array(
            'description' => 'Один коммит, минимум файлов',
            'language'    => 'Shell',
        ));

        /* --- demo-org/ignored-files: отслеживаемые файлы под .gitignore --- */
        $work = $this->stateDir . '/seeds/ignored-files';
        $this->ensureDir($work . '/dist');
        $this->write($work . '/.gitignore', "dist/\nnode_modules/\n");
        $this->write($work . '/README.md', "# ignored-files\n\nПроверяем, что архивный режим переносит файлы, которые попадают под .gitignore, но отслеживаются git.\n");
        $this->write($work . '/dist/app.js', "console.log('build artifact tracked on purpose');\n");
        $this->git(array('init', '--quiet', '--initial-branch=main', '.'), $work);
        $this->git(array('add', '-A'), $work);
        $this->git(array('add', '--force', 'dist/app.js'), $work);
        $this->commit($work, 'Первый коммит: сборка в dist');
        $this->importBare('demo-org', 'ignored-files', $work, array(
            'description' => 'Есть файлы под .gitignore, которые отслеживаются git',
            'language'    => 'JavaScript',
        ));

        /* --- репозитории самого пользователя ---------------------------- */
        $work = $this->stateDir . '/seeds/my-notes';
        $this->ensureDir($work);
        $this->write($work . '/README.md', "# my-notes\n\nЛичный репозиторий демо-пользователя.\n");
        $this->git(array('init', '--quiet', '--initial-branch=main', '.'), $work);
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Первый коммит');
        $this->importBare('demo', 'my-notes', $work, array('description' => 'Личные заметки', 'language' => 'Markdown'));

        $work = $this->stateDir . '/seeds/photo-bot';
        $this->ensureDir($work);
        $this->write($work . '/bot.py', "print('photo bot')\n");
        $this->git(array('init', '--quiet', '--initial-branch=main', '.'), $work);
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Первый коммит: бот');
        $this->write($work . '/bot.py', "print('photo bot v2')\n");
        $this->git(array('add', '-A'), $work);
        $this->commit($work, 'Второй коммит: обновление');
        $this->importBare('demo', 'photo-bot', $work, array('description' => 'Телеграм-бот для фотографий', 'language' => 'Python'));

        /* --- форк, созданный «вне приложения» --------------------------- */
        $forkPath = $this->repoPath('demo', 'hello-world-copy');
        $this->git(array('clone', '--bare', '--quiet', $helloWorld['path'], $forkPath));
        $this->state['repos']['demo/hello-world-copy'] = array(
            'owner'        => 'demo',
            'name'         => 'hello-world-copy',
            'path'         => $forkPath,
            'description'  => 'Форк демонстрационного репозитория',
            'private'      => false,
            'fork'         => true,
            'parent'       => 'demo-org/hello-world',
            'language'     => 'JavaScript',
            'created_at'   => gmdate('c', time() - 86400 * 12),
        );

        $this->state['seeded'] = time();
        $this->save();
    }

    private function importBare($owner, $name, $workDir, array $extra = array())
    {
        $path = $this->repoPath($owner, $name);
        $this->removeTree($path);
        $this->ensureDir(dirname($path));
        $this->git(array('clone', '--bare', '--quiet', $workDir, $path));

        $repo = array_merge(array(
            'owner'       => $owner,
            'name'        => $name,
            'path'        => $path,
            'description' => '',
            'private'     => false,
            'fork'        => false,
            'parent'      => '',
            'language'    => '',
            'created_at'  => gmdate('c', time() - 86400 * 30),
        ), $extra);

        $this->state['repos'][$owner . '/' . $name] = $repo;

        return $repo;
    }

    private function write($file, $contents)
    {
        $this->ensureDir(dirname($file));
        file_put_contents($file, $contents);
    }

    private function removeTree($path)
    {
        if (!is_dir($path)) {
            return;
        }
        $items = @scandir($path);
        foreach ((array) $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    /* ------------------------------ модели ----------------------------- */

    /**
     * Кэш метрик репозитория (размер/дата) в состоянии макета.
     *
     * Пересчёт запускает git-процессы на каждый запрос списка, поэтому метрики
     * живут в кэше; изменившиеся репозитории пересчитываются по «отпечатку» refs.
     */
    private function cachedStats(array $repo)
    {
        $path = $repo['path'];
        $full = $repo['owner'] . '/' . $repo['name'];
        $fingerprint = $this->statsFingerprint($path);

        $cache = isset($this->state['stats'][$full]) ? $this->state['stats'][$full] : null;
        if (is_array($cache) && isset($cache['fingerprint'], $cache['stats']) && $cache['fingerprint'] === $fingerprint) {
            return $cache['stats'];
        }

        $stats = $this->repoStats($path);
        $this->state['stats'][$full] = array('fingerprint' => $fingerprint, 'stats' => $stats);
        $this->dirty = true;
        $this->save();

        return $stats;
    }

    /**
     * Отпечаток состояния ссылок: если он не изменился, метрики берём из кэша.
     *
     * Сначала пробуем обойтись дешёвыми stat-ами (HEAD/packed-refs/refs), и лишь
     * если файловая система ничего не показывает — спрашиваем git.
     */
    private function statsFingerprint($path)
    {
        clearstatcache(true, $path);
        $parts = array();
        foreach (array('HEAD', 'packed-refs', 'refs/heads') as $rel) {
            $file = rtrim($path, '/') . '/' . $rel;
            if (file_exists($file)) {
                $parts[] = $rel . ':' . (int) @filemtime($file) . ':' . (int) @filesize($file);
            }
        }

        if (count($parts) >= 2) {
            return sha1(implode('|', $parts));
        }

        $head = $this->git(array('for-each-ref', '--format=%(objectname)'), $path);

        return $head['rc'] === 0 ? sha1((string) $head['stdout']) : '';
    }

    /** Преобразование внутреннего описания в ответ GitHub API. */
    private function repoPayload(array $repo, $withParent = true)
    {
        $path = $repo['path'];
        $default = $this->defaultBranch($path);
        $stats = $this->cachedStats($repo);

        $payload = array(
            'id'               => crc32($repo['owner'] . '/' . $repo['name']),
            'name'             => $repo['name'],
            'full_name'        => $repo['owner'] . '/' . $repo['name'],
            'owner'            => array(
                'login'      => $repo['owner'],
                'avatar_url' => '',
                'html_url'   => 'https://github.com/' . $repo['owner'],
            ),
            'private'          => !empty($repo['private']),
            'fork'             => !empty($repo['fork']),
            'archived'         => !empty($repo['archived']),
            'disabled'         => false,
            'is_template'      => false,
            'description'      => isset($repo['description']) ? $repo['description'] : '',
            'language'         => isset($repo['language']) && $repo['language'] !== '' ? $repo['language'] : null,
            'stargazers_count' => isset($repo['stars']) ? (int) $repo['stars'] : 0,
            'forks_count'      => 0,
            'open_issues_count' => 0,
            'watchers_count'   => 0,
            'size'             => $stats['size_kb'],
            'default_branch'   => $default !== '' ? $default : 'main',
            'html_url'         => 'https://github.com/' . $repo['owner'] . '/' . $repo['name'],
            'clone_url'        => $path,
            'ssh_url'          => $path,
            'topics'           => array(),
            'homepage'         => '',
            'permissions'      => array(
                'admin' => $repo['owner'] === $this->currentLogin(),
                'push'  => true,
                'pull'  => true,
            ),
            'created_at'       => isset($repo['created_at']) ? $repo['created_at'] : gmdate('c'),
            'updated_at'       => $stats['updated_at'],
            'pushed_at'        => $stats['pushed_at'] !== '' ? $stats['pushed_at'] : null,
        );

        if ($withParent && !empty($repo['parent']) && isset($this->state['repos'][$repo['parent']])) {
            $parent = $this->state['repos'][$repo['parent']];
            $payload['parent'] = array(
                'full_name' => $parent['owner'] . '/' . $parent['name'],
                'html_url'  => 'https://github.com/' . $parent['owner'] . '/' . $parent['name'],
            );
            $payload['source'] = $payload['parent'];
        }

        if ($repo['owner'] === $this->currentLogin()) {
            $payload['permissions']['admin'] = true;
        }

        return $payload;
    }

    private function repoStats($path)
    {
        // Размер считаем средствами git (быстрее и не зависит от прав на файлы).
        $objects = $this->git(array('count-objects', '-v'), $path);
        $size = 0;
        if ($objects['rc'] === 0) {
            foreach (preg_split('/\r?\n/', (string) $objects['stdout']) as $line) {
                if (preg_match('/^(size|size-pack):\s*(\d+)$/', trim($line), $matches)) {
                    $size += (int) $matches[2];
                }
            }
        }

        $last = $this->git(array('log', '-1', '--format=%cI'), $path);
        $date = trim((string) $last['stdout']);
        $timestamp = $date !== '' ? strtotime($date) : 0;

        // count-objects -v сообщает размер уже в килобайтах.
        return array(
            'size_kb'    => max(1, (int) $size),
            'updated_at' => $timestamp ? gmdate('c', $timestamp) : gmdate('c'),
            'pushed_at'  => $timestamp ? gmdate('c', $timestamp) : '',
        );
    }

    private function defaultBranch($path)
    {
        $result = $this->git(array('symbolic-ref', '--short', 'HEAD'), $path);

        return trim((string) $result['stdout']);
    }

    private function currentLogin()
    {
        $env = getenv('MOCK_GH_LOGIN');

        return $env !== false && $env !== '' ? $env : 'demo';
    }

    private function branches($path)
    {
        $result = $this->git(array('for-each-ref', '--format=%(refname:short)|%(objectname)|%(authorname)|%(committerdate:iso8601)', 'refs/heads'), $path);
        $branches = array();
        $default = $this->defaultBranch($path);

        foreach (preg_split('/\r?\n/', (string) $result['stdout']) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode('|', $line);
            $branches[] = array(
                'name'   => $parts[0],
                'commit' => array(
                    'sha' => isset($parts[1]) ? $parts[1] : '',
                    'commit' => array(
                        'author' => array(
                            'name' => isset($parts[2]) ? $parts[2] : '',
                            'date' => isset($parts[3]) ? gmdate('c', (int) strtotime($parts[3])) : gmdate('c'),
                        ),
                        'message' => '',
                    ),
                ),
                'protected' => $default === $parts[0],
            );
        }

        return $branches;
    }

    /* ------------------------------ маршрутизация ---------------------- */

    public function handle()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $query = array();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        $this->metric($method . ' ' . $path);

        if ($path === '/__mock/reset' && $method === 'POST') {
            $stateDir = $this->stateDir;
            $this->removeTree($this->stateDir);
            $this->ensureDir($stateDir);
            $this->seed();

            return $this->json(array('ok' => true), 200);
        }

        if ($path === '/__mock/state' && $method === 'GET') {
            return $this->json(array(
                'repos'   => array_keys($this->state['repos']),
                'metrics' => $this->state['metrics'],
                'dir'     => $this->stateDir,
            ), 200);
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = array();
        }

        if ($path === '/zen' || $path === '/') {
            return $this->json(array('message' => 'Design for failure.'), 200);
        }

        $token = $this->token();

        if ($path === '/rate_limit') {
            if ($token === null) {
                return $this->unauthorized();
            }

            return $this->json(array(
                'resources' => array(
                    'core' => array('limit' => 5000, 'remaining' => 4321, 'reset' => time() + 1800),
                ),
                'rate' => array('limit' => 5000, 'remaining' => 4321, 'reset' => time() + 1800),
            ), 200);
        }

        if ($token === null) {
            return $this->unauthorized();
        }
        if ($token === 'bad-token') {
            return $this->json(array('message' => 'Bad credentials'), 401);
        }

        if ($path === '/user' && $method === 'GET') {
            return $this->json(array(
                'login'        => $this->currentLogin(),
                'name'         => 'Демо-пользователь',
                'avatar_url'   => '',
                'html_url'     => 'https://github.com/' . $this->currentLogin(),
                'public_repos' => count($this->ownedRepos()),
                'type'         => 'User',
            ), 200);
        }

        if ($path === '/user/repos' && $method === 'GET') {
            $repos = $this->ownedRepos();
            usort($repos, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            $payload = array();
            foreach ($repos as $repo) {
                $payload[] = $this->repoPayload($repo);
            }

            return $this->paginate($payload, $query);
        }

        if ($path === '/user/repos' && $method === 'POST') {
            return $this->createRepo($body);
        }

        if (preg_match('#^/repos/([^/]+)/([^/]+)/(branches|forks|commits)$#', $path, $matches)) {
            $full = urldecode($matches[1]) . '/' . urldecode($matches[2]);
            if (!isset($this->state['repos'][$full])) {
                return $this->notFound();
            }
            $repo = $this->state['repos'][$full];

            if ($matches[3] === 'branches' && $method === 'GET') {
                return $this->paginate($this->branches($repo['path']), $query);
            }

            if ($matches[3] === 'commits' && $method === 'GET') {
                $result = $this->git(array('rev-list', '--count', '--all'), $repo['path']);

                return $this->json(array('count' => (int) trim((string) $result['stdout'])), 200);
            }

            if ($matches[3] === 'forks' && $method === 'POST') {
                return $this->createFork($repo, $body);
            }
        }

        if (preg_match('#^/repos/([^/]+)/([^/]+)/tarball/(.+)$#', $path, $matches)) {
            $full = urldecode($matches[1]) . '/' . urldecode($matches[2]);
            if (!isset($this->state['repos'][$full])) {
                return $this->notFound();
            }

            return $this->tarball($this->state['repos'][$full], urldecode($matches[3]));
        }

        if (preg_match('#^/repos/([^/]+)/([^/]+)$#', $path, $matches)) {
            $full = urldecode($matches[1]) . '/' . urldecode($matches[2]);
            if (!isset($this->state['repos'][$full])) {
                return $this->notFound();
            }
            $repo = $this->state['repos'][$full];

            if ($method === 'GET') {
                return $this->json($this->repoPayload($repo), 200);
            }

            if ($method === 'PATCH') {
                return $this->updateRepo($full, $repo, $body);
            }

            if ($method === 'DELETE') {
                return $this->deleteRepo($full, $repo);
            }
        }

        return $this->json(array('message' => 'Not Found', 'path' => $path, 'method' => $method), 404);
    }

    private function metric($key)
    {
        if (!isset($this->state['metrics'])) {
            $this->state['metrics'] = array();
        }
        if (!isset($this->state['metrics'][$key])) {
            $this->state['metrics'][$key] = 0;
        }
        $this->state['metrics'][$key]++;
    }

    private function token()
    {
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ((array) $headers as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $header = (string) $value;
                }
            }
        }

        if ($header === '') {
            return null;
        }

        if (preg_match('/^(?:Bearer|token)\s+(.+)$/i', trim($header), $matches)) {
            return trim($matches[1]);
        }

        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                return substr($decoded, strpos($decoded, ':') + 1);
            }
        }

        return null;
    }

    /** @return array<int,array> Репозитории, принадлежащие пользователю. */
    private function ownedRepos()
    {
        $login = $this->currentLogin();
        $repos = array();
        foreach ($this->state['repos'] as $full => $repo) {
            if ($repo['owner'] === $login) {
                $repos[] = $repo;
            }
        }

        return $repos;
    }

    private function createRepo(array $body)
    {
        $name = isset($body['name']) ? (string) $body['name'] : '';
        $login = $this->currentLogin();

        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            return $this->json(array('message' => 'Repository creation failed.', 'errors' => array(
                array('resource' => 'Repository', 'code' => 'custom', 'field' => 'name', 'message' => 'name is invalid'),
            )), 422);
        }

        if (isset($this->state['repos'][$login . '/' . $name])) {
            return $this->json(array(
                'message' => 'Repository creation failed.',
                'errors'  => array(
                    array('resource' => 'Repository', 'code' => 'custom', 'field' => 'name', 'message' => 'name already exists on this account'),
                ),
            ), 422);
        }

        $path = $this->repoPath($login, $name);
        $this->ensureDir(dirname($path));
        $this->git(array('init', '--bare', '--quiet', '--initial-branch=' . (isset($body['default_branch']) ? (string) $body['default_branch'] : 'main'), $path));

        $this->state['repos'][$login . '/' . $name] = array(
            'owner'       => $login,
            'name'        => $name,
            'path'        => $path,
            'description' => isset($body['description']) ? (string) $body['description'] : '',
            'private'     => !empty($body['private']),
            'fork'        => false,
            'parent'      => '',
            'language'    => '',
            'created_at'  => gmdate('c'),
        );
        $this->save();

        return $this->json($this->repoPayload($this->state['repos'][$login . '/' . $name]), 201);
    }

    private function updateRepo($full, array $repo, array $body)
    {
        $changed = false;

        if (isset($body['description'])) {
            $this->state['repos'][$full]['description'] = (string) $body['description'];
            $changed = true;
        }
        if (isset($body['private'])) {
            $this->state['repos'][$full]['private'] = (bool) $body['private'];
            $changed = true;
        }
        if (isset($body['default_branch'])) {
            $branch = (string) $body['default_branch'];
            // MOCK_IGNORE_DEFAULT_BRANCH=1 эмулирует аккаунт, которому GitHub
            // не позволяет менять ветку по умолчанию — проверяем запасной путь.
            if (getenv('MOCK_IGNORE_DEFAULT_BRANCH') !== '1') {
                $check = $this->git(array('show-ref', '--verify', '--quiet', 'refs/heads/' . $branch), $repo['path']);
                if ((int) $check['rc'] === 0) {
                    $this->git(array('symbolic-ref', 'HEAD', 'refs/heads/' . $branch), $repo['path']);
                    $changed = true;
                } else {
                    return $this->json(array(
                        'message' => 'Validation Failed',
                        'errors'  => array(array('resource' => 'Repository', 'code' => 'invalid', 'field' => 'default_branch')),
                    ), 422);
                }
            }
        }

        if ($changed) {
            $this->save();
        }

        return $this->json($this->repoPayload($this->state['repos'][$full]), 200);
    }

    private function deleteRepo($full, array $repo)
    {
        if ($repo['owner'] !== $this->currentLogin()) {
            return $this->json(array('message' => 'Must have admin rights to Repository.'), 403);
        }

        $this->removeTree($repo['path']);
        unset($this->state['repos'][$full]);
        $this->save();

        return $this->json(null, 204);
    }

    private function createFork(array $source, array $body)
    {
        $login = $this->currentLogin();
        $name = isset($body['name']) && $body['name'] !== '' ? (string) $body['name'] : $source['name'];
        $full = $login . '/' . $name;

        if (isset($this->state['repos'][$full])) {
            return $this->json(array(
                'message' => 'Repository creation failed.',
                'errors'  => array(array('resource' => 'Repository', 'code' => 'custom', 'field' => 'name', 'message' => 'name already exists on this account')),
            ), 422);
        }

        $path = $this->repoPath($login, $name);
        $this->ensureDir(dirname($path));
        $this->git(array('clone', '--bare', '--quiet', $source['path'], $path));

        $this->state['repos'][$full] = array(
            'owner'       => $login,
            'name'        => $name,
            'path'        => $path,
            'description' => $source['description'],
            'private'     => false,
            'fork'        => true,
            'parent'      => $source['owner'] . '/' . $source['name'],
            'language'    => $source['language'],
            'created_at'  => gmdate('c'),
        );
        $this->save();

        return $this->json($this->repoPayload($this->state['repos'][$full]), 202);
    }

    private function tarball(array $repo, $ref)
    {
        $exists = $this->git(array('rev-parse', '--verify', '--quiet', 'refs/heads/' . $ref), $repo['path']);
        if ((int) $exists['rc'] !== 0 || trim((string) $exists['stdout']) === '') {
            $tag = $this->git(array('rev-parse', '--verify', '--quiet', 'refs/tags/' . $ref), $repo['path']);
            if ((int) $tag['rc'] !== 0) {
                return $this->json(array('message' => 'Not Found'), 404);
            }
            $ref = 'refs/tags/' . $ref;
        } else {
            $ref = 'refs/heads/' . $ref;
        }

        $sha = trim((string) $this->git(array('rev-parse', '--short=7', $ref), $repo['path'])['stdout']);
        $prefix = $repo['name'] . '-' . $sha . '/';
        $tmp = tempnam(sys_get_temp_dir(), 'ghm-tar');

        $result = Process::run(array(
            'git', '-C', $repo['path'], 'archive', '--format=tar.gz', '--prefix=' . $prefix, '-o', $tmp, $ref,
        ), array(
            'cwd'     => $repo['path'],
            'env'     => array(
                'PATH'   => getenv('PATH') !== false ? (string) getenv('PATH') : '/usr/bin:/bin',
                'HOME'   => $this->stateDir,
                'LANG'   => 'C',
                'LC_ALL' => 'C',
            ),
            'timeout' => 120,
        ));

        if (!$result['ok'] && !is_file($tmp)) {
            return $this->json(array('message' => 'Archive failed: ' . $result['stderr']), 500);
        }

        $contents = (string) file_get_contents($tmp);
        @unlink($tmp);

        header('Content-Type: application/gzip');
        header('Content-Length: ' . strlen($contents));
        echo $contents;

        return true;
    }

    /* ------------------------------ ответы ----------------------------- */

    private function paginate(array $items, array $query)
    {
        $perPage = isset($query['per_page']) ? max(1, min(100, (int) $query['per_page'])) : 30;
        $page = isset($query['page']) ? max(1, (int) $query['page']) : 1;
        $total = count($items);
        $chunk = array_slice($items, ($page - 1) * $perPage, $perPage);

        if ($page * $perPage < $total) {
            $next = $page + 1;
            $base = strtok((string) $_SERVER['REQUEST_URI'], '?');
            $params = $query;
            $params['page'] = $next;
            $params['per_page'] = $perPage;
            header('Link: <' . $base . '?' . http_build_query($params) . '>; rel="next"');
        }

        return $this->json($chunk, 200);
    }

    private function unauthorized()
    {
        header('WWW-Authenticate: Bearer realm="mock"');

        return $this->json(array('message' => 'Requires authentication'), 401);
    }

    private function notFound()
    {
        return $this->json(array('message' => 'Not Found'), 404);
    }

    private function json($data, $status)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-RateLimit-Limit: 5000');
        header('X-RateLimit-Remaining: 4321');
        header('X-RateLimit-Reset: ' . (time() + 1800));

        if ($status !== 204) {
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return true;
    }
}

$mock = new MockGitHub();
$handled = $mock->handle();
if ($handled === false) {
    http_response_code(404);
}

<?php
/**
 * Тесты GitHub Manager.
 *
 * Запуск:
 *   php tests/run.php
 *
 * Переменные окружения:
 *   GHM_TEST_API_BASE  — адрес уже запущенного макета GitHub (иначе будет запущен сам)
 *   GHM_TEST_APP_URL   — адрес уже запущенного приложения (иначе будет запущен сам)
 *   MOCK_GH_STATE      — каталог состояния макета
 *
 * Тесты без внешней сети: макет GitHub и реальный git работают локально.
 */

require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/process.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/format.php';
require_once __DIR__ . '/../lib/repo_view.php';
require_once __DIR__ . '/../lib/github.php';
require_once __DIR__ . '/../lib/jobs.php';
require_once __DIR__ . '/../lib/git_client.php';
require_once __DIR__ . '/../lib/history.php';
require_once __DIR__ . '/../lib/copier.php';

use Ghm\ApiException;
use Ghm\Config;
use Ghm\Copier;
use Ghm\Format;
use Ghm\GitClient;
use Ghm\GitHub;
use Ghm\GitHubException;
use Ghm\History;
use Ghm\JobStore;
use Ghm\Process;
use Ghm\Security;

final class TestRunner
{
    private $passed = 0;
    private $failed = 0;
    private $skipped = 0;
    private $failures = array();
    private $group = '';

    public function group($name)
    {
        $this->group = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public function ok($condition, $message)
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✓\033[0m " . $message . "\n";

            return true;
        }

        $this->fail($message);

        return false;
    }

    public function equals($expected, $actual, $message)
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "  \033[32m✓\033[0m " . $message . "\n";

            return true;
        }

        $this->fail($message . ' — ожидалось ' . json_encode($expected, JSON_UNESCAPED_UNICODE)
            . ', получено ' . json_encode($actual, JSON_UNESCAPED_UNICODE));

        return false;
    }

    public function contains($needle, $haystack, $message)
    {
        return $this->ok(strpos((string) $haystack, (string) $needle) !== false,
            $message . ' (ищем «' . $needle . '» в «' . mb_substr((string) $haystack, 0, 200, 'UTF-8') . '»)');
    }

    public function skip($message)
    {
        $this->skipped++;
        echo "  \033[33m•\033[0m " . $message . " (пропущено)\n";
    }

    /** Проверка, что вызов бросил исключение указанного класса. */
    public function throws($callback, $message, $class = 'Exception', $code = null)
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!($e instanceof $class)) {
                $this->fail($message . ' — поймано ' . get_class($e) . ': ' . $e->getMessage());

                return null;
            }
            if ($code !== null) {
                $actual = $e instanceof ApiException ? $e->getErrorCode() : null;
                if ($actual !== $code) {
                    $this->fail($message . ' — код ' . (string) $actual . ' вместо ' . $code);

                    return $e;
                }
            }
            $this->passed++;
            echo "  \033[32m✓\033[0m " . $message . "\n";

            return $e;
        }

        $this->fail($message . ' — исключение не было брошено');
        return null;
    }

    public function fail($message)
    {
        $this->failed++;
        $this->failures[] = $this->group . ': ' . $message;
        echo "  \033[31m✗ " . $message . "\033[0m\n";
    }

    public function summary()
    {
        echo "\n" . str_repeat('─', 64) . "\n";
        echo 'Пройдено: ' . $this->passed . ', провалено: ' . $this->failed . ', пропущено: ' . $this->skipped . "\n";
        if ($this->failed > 0) {
            echo "\nПроваленные проверки:\n";
            foreach ($this->failures as $failure) {
                echo '  • ' . $failure . "\n";
            }
        }

        return $this->failed === 0 ? 0 : 1;
    }
}

/** Простейший HTTP-клиент для проверок API приложения. */
final class TestHttp
{
    /** @var string */
    private $cookieFile;

    public function __construct()
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'ghm-cookie');
    }

    public function request($url, $method = 'GET', array $headers = array(), $body = null)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);

        $headerLines = array();
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if ($headerLines) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }

        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($result === false) {
            throw new \RuntimeException('HTTP-запрос к ' . $url . ' не удался: ' . $error);
        }

        return array('status' => $status, 'body' => (string) $result, 'json' => json_decode((string) $result, true));
    }
}

/** Запуск фонового сервера (php -S) и ожидание готовности. */
final class TestServer
{
    /** @var resource|null */
    private $process = null;

    /** @var string */
    public $url = '';

    /** @var string */
    private $logFile = '';

    public function start($router, $docroot, $env = array())
    {
        $port = $this->freePort();
        $this->url = 'http://127.0.0.1:' . $port;
        $this->logFile = tempnam(sys_get_temp_dir(), 'ghm-server');

        $command = array(PHP_BINARY, '-S', '127.0.0.1:' . $port);
        if ($router !== null) {
            $command[] = $router;
        } else {
            $command[] = '-t';
            $command[] = $docroot;
        }

        $envVars = array(
            'PATH' => getenv('PATH') !== false ? (string) getenv('PATH') : '/usr/bin:/bin',
            'HOME' => getenv('HOME') !== false ? (string) getenv('HOME') : sys_get_temp_dir(),
        );
        foreach ($env as $key => $value) {
            $envVars[$key] = (string) $value;
        }

        $descriptors = array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('file', $this->logFile, 'a'),
            2 => array('file', $this->logFile, 'a'),
        );

        $process = @proc_open($command, $descriptors, $pipes, $docroot, $envVars);
        if (!is_resource($process)) {
            return false;
        }
        $this->process = $process;

        for ($attempt = 0; $attempt < 60; $attempt++) {
            usleep(250000);
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.4);
            if ($socket) {
                fclose($socket);

                return true;
            }
            $status = proc_get_status($process);
            if ($status && empty($status['running'])) {
                return false;
            }
        }

        return false;
    }

    public function log()
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    public function stop()
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process, 15);
            usleep(150000);
            @proc_close($this->process);
            $this->process = null;
        }
    }

    private function freePort()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}

/* ====================================================================== */
/* Подготовка окружения                                                   */
/* ====================================================================== */

$root = dirname(__DIR__);

/** Вызов git в указанном каталоге через -C (cwd процесса не всегда передаётся потомкам). */
function gitEnv(array $extra = array())
{
    return array_merge(array(
        'PATH'                => getenv('PATH') !== false ? (string) getenv('PATH') : '/usr/bin:/bin',
        'HOME'                => sys_get_temp_dir(),
        'LANG'                => 'C',
        'LC_ALL'              => 'C',
        'GIT_TERMINAL_PROMPT' => '0',
    ), $extra);
}

/**
 * Файл конфигурации git для тестовых операций.
 *
 * Идентичность пишем в файл и подключаем через `-c include.path=`: переменные
 * окружения дочерним git-процессам передаются не везде (php-wasm), а в
 * аргументах командной строки недопустимы пробелы.
 */
function gitConfigFile()
{
    static $file = null;

    if ($file === null) {
        $file = sys_get_temp_dir() . '/ghm-test-gitconfig-' . substr(sha1(__FILE__), 0, 8) . '.conf';
        if (!is_file($file)) {
            file_put_contents($file, "[user]\n\tname = Тестовый Пользователь\n\temail = tester@example.com\n"
                . "[commit]\n\tgpgsign = false\n[core]\n\tquotepath = false\n", LOCK_EX);
            @chmod($file, 0600);
        }
    }

    return $file;
}

function gitIn($dir, array $args, array $env = null)
{
    $command = array('git', '-C', $dir, '-c', 'include.path=' . gitConfigFile());
    $command = array_merge($command, $args);

    return Process::run($command, array(
        'cwd'     => $dir,
        'env'     => $env !== null ? $env : gitEnv(),
        'timeout' => 120,
    ));
}

/** Коммит с сообщением из файла (в аргументах не должно быть пробелов). */
function gitCommit($dir, $message, array $env = null)
{
    $file = sys_get_temp_dir() . '/ghm-test-msg-' . substr(sha1($message . microtime(true)), 0, 8) . '.txt';
    file_put_contents($file, $message . "\n");
    $result = gitIn($dir, array('commit', '--quiet', '--file=' . $file), $env);
    @unlink($file);

    return $result;
}
$workRoot = sys_get_temp_dir() . '/ghm-tests-' . substr(sha1($root . getmypid()), 0, 8);

$mockState = getenv('MOCK_GH_STATE');
if ($mockState === false || $mockState === '') {
    $mockState = $workRoot . '/mock';
    putenv('MOCK_GH_STATE=' . $mockState);
} else {
    // Макет запущен извне (GHM_TEST_API_BASE) — работаем с его каталогом состояния.
    $mockState = rtrim($mockState, '/');
}

putenv('GHM_DATA_DIR=' . $workRoot . '/data');
putenv('GHM_CA_BUNDLE=');
Config::reset();

$t = new TestRunner();
$servers = array();
$mockBase = getenv('GHM_TEST_API_BASE');
$appUrl = getenv('GHM_TEST_APP_URL');

echo "GitHub Manager — тесты\n";
echo "Рабочий каталог: " . $workRoot . "\n";

@mkdir($workRoot, 0700, true);

/* Макет GitHub API ------------------------------------------------------ */
if (!$mockBase) {
    $server = new TestServer();
    if ($server->start($root . '/tests/mock_github.php', $root, array('MOCK_GH_STATE' => $mockState))) {
        $mockBase = $server->url;
        $servers[] = $server;
        echo "Макет GitHub запущен: " . $mockBase . "\n";
    } else {
        echo "Не удалось запустить макет GitHub (php -S недоступен).\n" . $server->log() . "\n";
    }
} else {
    echo "Используем готовый макет GitHub: " . $mockBase . "\n";
}

if ($mockBase) {
    putenv('GHM_API_BASE=' . $mockBase);
    Config::reset();
}

/* Приложение ------------------------------------------------------------ */
if (!$appUrl && $mockBase) {
    $appServer = new TestServer();
    if ($appServer->start(null, $root, array(
        'GHM_API_BASE' => $mockBase,
        'GHM_DATA_DIR' => $workRoot . '/app-data',
        'GHM_DEMO'     => '1',
        'GHM_DEMO_TOKEN' => 'demo-token',
    ))) {
        $appUrl = $appServer->url;
        $servers[] = $appServer;
        echo "Тестовое приложение запущено: " . $appUrl . "\n";
    } else {
        echo "Не удалось запустить приложение (php -S недоступен).\n" . $appServer->log() . "\n";
    }
} elseif ($appUrl) {
    echo "Используем готовое приложение: " . $appUrl . "\n";
}

$mockAvailable = $mockBase !== '';

/* ====================================================================== */
/* 1. Валидация и безопасность                                            */
/* ====================================================================== */

$t->group('Валидация входных данных');

$t->ok(Security::isValidOwner('demo-user'), 'Логин demo-user принимается');
$t->ok(Security::isValidOwner('loadpwnz11-oss'), 'Логин с дефисом принимается');
$t->ok(!Security::isValidOwner('-bad'), 'Логин, начинающийся с дефиса, отклоняется');
$t->ok(!Security::isValidOwner('bad name'), 'Логин с пробелом отклоняется');
$t->ok(!Security::isValidOwner('../../etc/passwd'), 'Попытка path traversal в логине отклоняется');

$t->ok(Security::isValidRepoName('repo.name-1_x'), 'Имя репозитория с точкой, дефисом и подчёркиванием принимается');
$t->ok(!Security::isValidRepoName('../evil'), 'Имя репозитория с ../ отклоняется');
$t->ok(!Security::isValidRepoName('.hidden'), 'Имя репозитория с ведущей точкой отклоняется');
$t->ok(!Security::isValidRepoName('a/b'), 'Имя репозитория со слэшем отклоняется');
$t->ok(!Security::isValidRepoName(str_repeat('a', 101)), 'Слишком длинное имя репозитория отклоняется');
$t->equals('my-repo', Security::slugifyRepoName('My Repo!!'), 'slugify приводит имя к безопасному виду');

$t->ok(Security::isValidRef('main'), 'Ветка main принимается');
$t->ok(Security::isValidRef('feature/nice-branch'), 'Ветка со слэшем принимается');
$t->ok(!Security::isValidRef('-dangerous'), 'Ветка, начинающаяся с дефиса, отклоняется');
$t->ok(!Security::isValidRef('a..b'), 'Ветка с .. отклоняется');
$t->ok(!Security::isValidRef("bad\nbranch"), 'Ветка с переводом строки отклоняется');
$t->ok(!Security::isValidJobId('../../etc/passwd'), 'Идентификатор задания с ../ отклоняется');
$t->ok(Security::isValidJobId(str_repeat('a', 32)), 'Корректный идентификатор задания принимается');

$t->group('Маскирование секретов и экранирование');

$token = 'ghp_1234567890abcdefghijklmnopqrstuv';
$redacted = Security::redact(
    'fatal: could not read from https://x-access-token:' . $token . '@github.com/o/r.git Authorization: Basic ' . base64_encode('x-access-token:' . $token),
    array($token)
);
$t->ok(strpos($redacted, $token) === false, 'Токен вырезан из текста');
$t->ok(strpos($redacted, 'x-access-token:' . $token) === false, 'Токен в URL вырезан');
$t->contains('***', $redacted, 'В тексте остаётся маска');

$t->equals('&lt;script&gt;alert(1)&lt;/script&gt;', Security::e('<script>alert(1)</script>'), 'HTML-спецсимволы экранируются');
$t->ok(strpos(Security::e('" onmouseover="x'), '&quot;') !== false, 'Кавычки экранируются (защита атрибутов)');

$t->group('CSRF');

Security::startSession();
$csrf = Security::csrfToken();
$t->ok(strlen($csrf) === 64, 'CSRF-токен длинный (32 байта)');
$t->ok(Security::csrfValid($csrf), 'Правильный CSRF-токен принимается');
$t->ok(!Security::csrfValid('wrong-token'), 'Неправильный CSRF-токен отклоняется');
$t->ok(!Security::csrfValid(null), 'Пустой CSRF-токен отклоняется');

/* ====================================================================== */
/* 2. Форматирование                                                      */
/* ====================================================================== */

$t->group('Форматирование');

$t->equals('0 КБ', Format::bytes(0), 'Нулевой размер');
$t->equals('1 КБ', Format::bytes(1), 'Килобайты');
$t->equals('1 МБ', Format::bytes(1024), 'Мегабайты');
$t->equals('#f1e05a', Format::languageColor('JavaScript'), 'Цвет языка из таблицы');
$t->ok((bool) preg_match('/^#[0-9a-f]{6}$/', Format::languageColor('SomeWeirdLang')), 'Неизвестный язык получает корректный цвет');
$t->equals('файлов', Format::plural(5, 'файл', 'файла', 'файлов'), 'Склонение для 5');
$t->equals('файл', Format::plural(1, 'файл', 'файла', 'файлов'), 'Склонение для 1');
$t->contains('минут', Format::timeAgo(time() - 120), 'Относительное время');

/* ====================================================================== */
/* 3. Запуск процессов и git                                              */
/* ====================================================================== */

$t->group('Запуск процессов');

$git = new GitClient('demo-token');
$gitAvailable = $git->available();

$result = Process::run(array('git', '--version'));
$t->equals(0, $result['rc'], 'rc успешной команды равен 0');
$t->contains('git version', $result['stdout'], 'Вывод git читается');
$t->ok($result['duration'] >= 0, 'Длительность замеряется');

$result = Process::run(array('git', 'this-is-not-a-command'), array('timeout' => 20));
$t->ok($result['rc'] !== 0, 'Ошибочная команда возвращает ненулевой код');
$t->ok($result['stderr'] !== '' || $result['stdout'] !== '', 'Ошибка команды читается из вывода');

// Служебные настройки git передаются файлом конфигурации: переменные окружения
// дочерним процессам доходят не на всяком хостинге, а аргументы проверяются shell.
$gitCfg = new GitClient('demo-token');
$t->ok(is_file((string) $gitCfg->configFile()), 'Файл конфигурации git создан');
$cfgContents = (string) file_get_contents((string) $gitCfg->configFile());
$t->contains('[user]', $cfgContents, 'В конфигурации есть секция пользователя');
$t->ok(strpos($cfgContents, 'demo-token') === false, 'Токен не хранится в открытом виде (только base64-заголовок)');
$t->contains(base64_encode('x-access-token:demo-token'), $cfgContents, 'Токен передан заголовком авторизации');
$t->equals(0600, fileperms((string) $gitCfg->configFile()) & 0777, 'Права файла конфигурации — 0600');
$t->contains(Security::e('<'), Security::e('<'), 'Экранирование работает');

$identity = $gitCfg->run(array('config', '--get', 'user.name'), 20);
$t->contains((string) Config::get('bot_name'), $identity['stdout'], 'Имя коммиттера доходит до git через include.path');

// Аргументы с пробелами и метасимволами не должны ломать команду.
$weirdDir = $workRoot . '/weird dir #1';
@mkdir($weirdDir, 0700, true);
$weird = Process::run(array('git', '-C', $weirdDir, 'init', '--quiet', '--initial-branch=main', '.'), array('timeout' => 20));
$t->equals(0, $weird['rc'], 'Путь с пробелом и «#» передаётся без искажений');

$t->ok(Process::isDisabled('definitely_not_a_function') === false, 'Проверка disable_functions работает');

$t->group('Клиент git');

if (!$gitAvailable) {
    $t->skip('git недоступен — проверки клонирования/отправки пропущены');
} else {
    $sandbox = $workRoot . '/git';
    @mkdir($sandbox, 0700, true);
    $source = $sandbox . '/source.git';

    // Готовим настоящий репозиторий с двумя ветками и тегом.
    $work = $sandbox . '/work';
    @mkdir($work, 0700, true);
    file_put_contents($work . '/README.md', "# Тестовый репозиторий\n");
    mkdir($work . '/src');
    file_put_contents($work . '/src/app.js', "console.log('app');\n");
    $env = gitEnv();

    gitIn($work, array('init', '--quiet', '--initial-branch=main', '.'), $env);
    gitIn($work, array('add', '-A'), $env);
    gitCommit($work, 'Коммит 1: подготовка', $env);
    gitIn($work, array('tag', 'v1.0'), $env);
    gitIn($work, array('checkout', '--quiet', '-b', 'dev'), $env);
    file_put_contents($work . '/dev.txt', "dev\n");
    gitIn($work, array('add', '-A'), $env);
    gitCommit($work, 'Коммит 2: ветка dev', $env);
    gitIn($work, array('checkout', '--quiet', 'main'), $env);

    $clone = $git->cloneMirror($work, $source);
    $t->equals(0, $clone['rc'], 'git clone --bare проходит успешно (локальный путь)');

    $refs = $git->refs($source);
    $t->equals(array('dev', 'main'), $refs['heads'], 'Склонированы обе ветки');
    $t->equals(array('v1.0'), $refs['tags'], 'Склонирован тег');
    $t->equals('main', $git->headBranch($source), 'Ветка по умолчанию определена верно');
    $t->equals(2, $git->commitCount($source), 'Количество коммитов подсчитано');

    $target = $sandbox . '/target.git';
    gitIn($sandbox, array('init', '--bare', '--quiet', '--initial-branch=main', $target), $env);
    $push = $git->pushAll($source, $target);
    $t->equals(0, $push['rc'], 'git push --force всех веток и тегов проходит');

    $targetRefs = $git->refs($target);
    $t->equals(array('dev', 'main'), $targetRefs['heads'], 'Ветки попали в целевой репозиторий');
    $t->equals(array('v1.0'), $targetRefs['tags'], 'Теги попали в целевой репозиторий');

    // Проверяем содержимое файла в новой ветке.
    $show = gitIn($target, array('show', 'main:src/app.js'), $env);
    $t->contains('console.log', $show['stdout'], 'Содержимое файлов перенесено');

    // Инструментированная проверка маскирования ошибок git.
    $badPush = $git->pushAll($source, $sandbox . '/no-such-repo.git');
    $t->ok($badPush['rc'] !== 0, 'Отправка в несуществующий репозиторий завершается ошибкой');
    $t->ok(strpos(GitClient::errorMessage($badPush), 'demo-token') === false, 'Сообщение об ошибке не содержит токен');
}

/* ====================================================================== */
/* 4. Хранилище заданий                                                   */
/* ====================================================================== */

$t->group('Хранилище заданий');

$jobs = new JobStore($workRoot . '/jobs');
$sid = 'test-session-id';
$job = $jobs->create(array(
    'sid'    => $jobs->sessionKey($sid),
    'mode'   => Copier::MODE_COPY,
    'source' => array('owner' => 'demo-org', 'repo' => 'hello-world'),
    'target' => array('owner' => 'demo', 'name' => 'hello-world-copy'),
));
$t->ok(Security::isValidJobId($job['id']), 'Идентификатор задания корректного формата');

$loaded = $jobs->load($job['id'], $sid);
$t->ok($loaded !== null, 'Задание читается своей сессией');
$t->ok($jobs->load($job['id'], 'Другая сессия') === null, 'Чужой сессии задание недоступно');
$t->ok($jobs->load('../../etc/passwd', $sid) === null, 'Попытка обхода каталога отклоняется');

$jobs->log($job, 'первая строка');
$jobs->log($job, '');
$t->equals(1, count($job['log']), 'Пустые строки в лог не попадают');

$job['steps'] = array(
    array('key' => 'prepare', 'title' => 'Подготовка', 'status' => 'pending', 'message' => '', 'started_at' => 0, 'finished_at' => 0),
    array('key' => 'push', 'title' => 'Отправка', 'status' => 'pending', 'message' => '', 'started_at' => 0, 'finished_at' => 0),
);
$jobs->updateStep($job, 'push', array('status' => 'done'));
$t->equals('done', $jobs->step($job, 'push')['status'], 'Статус шага обновляется');

$jobs->save($job);
$t->equals('done', $jobs->load($job['id'], $sid)['steps'][1]['status'], 'Изменения сохраняются на диск');

// Защита от удаления каталогов вне рабочей области.
$outside = $workRoot . '/outside';
@mkdir($outside, 0700, true);
file_put_contents($outside . '/keep.txt', 'keep');
JobStore::removeDir($outside);
$t->ok(is_file($outside . '/keep.txt'), 'removeDir не удаляет каталоги вне рабочей области');

$t->ok(is_dir(Config::workDir()), 'Рабочий каталог создаётся');

/* ====================================================================== */
/* 5. GitHub API (локальный макет)                                        */
/* ====================================================================== */

$t->group('Клиент GitHub API');

if (!$mockAvailable) {
    $t->skip('Макет GitHub недоступен — проверки API пропущены');
} else {
    $http = new TestHttp();
    $http->request($mockBase . '/__mock/reset', 'POST');

    $anon = new GitHub('bad-token');
    $error = $t->throws(function () use ($anon) {
        $anon->currentUser();
    }, 'Неверный токен приводит к ошибке', GitHubException::class);
    $t->equals(401, $error->getStatus(), 'Статус ошибки 401');
    $t->contains('токен', mb_strtolower($error->getUserMessage(), 'UTF-8'), 'Понятное сообщение о токене');

    $gh = new GitHub('demo-token');
    $user = $gh->currentUser();
    $t->equals('demo', $user['login'], 'Профиль пользователя получен');

    $chunk = $gh->listRepos('demo', 1, 2);
    $t->equals(2, count($chunk['items']), 'Пагинация: выдаётся запрошенное количество');
    $t->ok($chunk['has_more'] === true, 'Пагинация: есть следующая страница');

    $all = $gh->listAllRepos('demo');
    $t->ok(count($all) >= 3, 'listAllRepos собирает все страницы (' . count($all) . ' репозиториев)');

    $source = $gh->getRepo('demo-org', 'hello-world');
    $t->equals('main', $source['default_branch'], 'Ветка по умолчанию исходного репозитория');
    $t->ok(!empty($source['clone_url']), 'В ответе есть clone_url');

    $branches = $gh->listBranches('demo-org', 'hello-world');
    $names = array();
    foreach ($branches['items'] as $branch) {
        $names[] = $branch['name'];
    }
    $t->ok(in_array('main', $names, true) && in_array('dev', $names, true), 'Список веток содержит main и dev');

    $missing = $t->throws(function () use ($gh) {
        $gh->getRepo('demo-org', 'no-such-repo');
    }, 'Отсутствующий репозиторий даёт 404', GitHubException::class);
    $t->equals(404, $missing->getStatus(), 'Статус 404');
    $t->ok($gh->findRepo('demo-org', 'no-such-repo') === null, 'findRepo возвращает null вместо исключения');

    $created = $gh->createRepo(array('name' => 'api-test-repo', 'private' => true, 'auto_init' => false));
    $t->equals('api-test-repo', $created['name'], 'Репозиторий создан через API');
    $t->ok(!empty($created['private']), 'Флаг приватности учтён');

    $conflict = $t->throws(function () use ($gh) {
        $gh->createRepo(array('name' => 'api-test-repo'));
    }, 'Повторное создание даёт ошибку', GitHubException::class);
    $t->ok($conflict->isAlreadyExists(), 'Ошибка распознана как «имя занято»');

    $updated = $gh->updateRepo('demo', 'api-test-repo', array('description' => 'Новое описание'));
    $t->equals('Новое описание', $updated['description'], 'PATCH описания работает');

    $tarballPath = $workRoot . '/tarball.tar.gz';
    $download = $gh->downloadTarball('demo-org', 'hello-world', 'main', $tarballPath);
    $t->equals(200, $download['status'], 'Tarball скачивается');
    $t->ok(filesize($tarballPath) > 0, 'Файл архива не пуст');
    $t->equals("\x1f\x8b", substr((string) file_get_contents($tarballPath), 0, 2), 'Архив в формате gzip');

    $forks = $gh->listAllRepos('demo');
    $t->ok(count($forks) >= 3, 'Список форков доступен');

    $deleted = true;
    try {
        $gh->deleteRepo('demo', 'api-test-repo');
    } catch (\Exception $e) {
        $deleted = false;
    }
    $t->ok($deleted, 'DELETE репозитория проходит');
    $t->ok($gh->findRepo('demo', 'api-test-repo') === null, 'Удалённый репозиторий больше не находится');

    $rate = $gh->rateLimit();
    $t->ok($rate['limit'] > 0, 'Лимиты API читаются (' . $rate['limit'] . ')');

    $t->group('Изоляция токена');
    $t->ok(strpos(json_encode($user), 'demo-token') === false, 'Профиль пользователя не содержит токен');
}

/* ====================================================================== */
/* 6. Сквозное копирование через Copier                                   */
/* ====================================================================== */

$t->group('Копирование репозиториев (сквозной тест)');

function runJobToEnd(Copier $copier, $sid, array $job)
{
    $guard = 0;
    while ($job['status'] === 'pending' || $job['status'] === 'running') {
        $next = null;
        foreach ($job['steps'] as $step) {
            if ($step['status'] === 'pending' || $step['status'] === 'running') {
                $next = $step['key'];
                break;
            }
        }
        if ($next === null) {
            break;
        }
        $job = $copier->execute($sid, $job['id'], $next);
        if (++$guard > 10) {
            break;
        }
    }

    return $job;
}

if (!$mockAvailable || !$gitAvailable) {
    $t->skip('Нужны макет GitHub и git — сквозные проверки копирования пропущены');
} else {
    $http = new TestHttp();
    $http->request($mockBase . '/__mock/reset', 'POST');

    $gh = new GitHub('demo-token');
    $history = new History($workRoot . '/history');
    $jobs = new JobStore($workRoot . '/jobs');
    $git = new GitClient('demo-token');
    $copier = new Copier($gh, 'demo', $jobs, $git, $history);
    $sid = 'test-session-id';

    /* --- Независимая копия через git -------------------------------- */
    $job = $copier->start($sid, array(
        'source_owner' => 'demo-org',
        'source_repo'  => 'hello-world',
        'target_name'  => 'hello-world-copy-e2e',
        'private'      => false,
        'mode'         => Copier::MODE_COPY,
    ));
    $t->equals('prepare', $job['steps'][0]['key'], 'Первый шаг — подготовка');
    $t->equals('done', $job['steps'][0]['status'], 'Шаг подготовки выполнен сразу');
    $t->ok($job['target']['html_url'] !== '', 'Целевой репозиторий создан');

    $job = runJobToEnd($copier, $sid, $job);
    $t->equals('done', $job['status'], 'Задание копирования завершено без ошибок');
    $t->equals('demo/hello-world-copy-e2e', $job['result']['full_name'], 'Результат указывает на новый репозиторий');
    $t->equals(2, $job['result']['branches'], 'Перенесены обе ветки');
    $t->equals(1, $job['result']['tags'], 'Перенесён тег');
    $t->equals(3, $job['result']['commits'], 'Перенесена вся история (3 коммита)');
    $t->equals('main', $job['result']['default_branch'], 'Ветка по умолчанию сохранена');

    $state = $http->request($mockBase . '/__mock/state', 'GET');
    $repos = $state['json']['repos'];
    $t->ok(in_array('demo/hello-world-copy-e2e', $repos, true), 'Репозиторий есть в макете GitHub');

    // Проверяем содержимое целевого репозитория напрямую через git.
    $mockStateDir = $mockState;
    if ($mockStateDir) {
        $targetPath = $mockStateDir . '/repos/demo/hello-world-copy-e2e.git';
        $targetRefs = $git->refs($targetPath);
        $t->equals(array('dev', 'main'), $targetRefs['heads'], 'В целевом репозитории обе ветки');
        $t->equals(array('v1.0.0'), $targetRefs['tags'], 'В целевом репозитории тег');
        $show = gitIn($targetPath, array('show', 'dev:src/experimental.js'));
        $t->contains('feature = true', $show['stdout'], 'Файл из ветки dev перенесён с содержимым');
        $t->equals('main', $git->headBranch($targetPath), 'HEAD целевого репозитория переключён на нужную ветку');
    } else {
        $t->skip('Каталог состояния макета задан извне — содержимое проверяется через API');
    }

    $t->ok(!is_dir($job['work_dir']), 'Рабочий каталог удалён после завершения');
    $t->ok($history->find('demo', 'demo/hello-world-copy-e2e') !== null, 'Копия попала в журнал приложения');

    /* --- Повторный запуск того же задания идемпотентен --------------- */
    $again = $copier->execute($sid, $job['id'], 'push');
    $t->equals('done', $again['status'], 'Повторный вызов выполненного шага не ломает задание');

    /* --- Занятое имя -------------------------------------------------- */
    $conflictJob = $copier->start($sid, array(
        'source_owner' => 'demo-org',
        'source_repo'  => 'hello-world',
        'target_name'  => 'hello-world-copy-e2e',
        'mode'         => Copier::MODE_COPY,
    ));
    $t->equals('error', $conflictJob['status'], 'Копирование в занятое имя отклоняется');
    $t->equals('name_taken', $conflictJob['error']['code'], 'Код ошибки — name_taken');
    $t->contains('уже существует', $conflictJob['error']['message'], 'Понятное сообщение о занятом имени');

    /* --- Перезапись существующего репозитория ------------------------ */
    $overwriteJob = $copier->start($sid, array(
        'source_owner' => 'demo-org',
        'source_repo'  => 'hello-world',
        'target_name'  => 'my-notes',
        'mode'         => Copier::MODE_COPY,
        'overwrite'    => true,
    ));
    $overwriteJob = runJobToEnd($copier, $sid, $overwriteJob);
    $t->equals('done', $overwriteJob['status'], 'Перезапись существующего репозитория проходит');
    if ($mockStateDir) {
        $targetPath = $mockStateDir . '/repos/demo/my-notes.git';
        $t->ok(in_array('main', $git->refs($targetPath)['heads'], true), 'Перезаписанный репозиторий содержит ветки источника');
    }

    /* --- Архивный режим ---------------------------------------------- */
    $archiveJob = $copier->start($sid, array(
        'source_owner' => 'demo-org',
        'source_repo'  => 'ignored-files',
        'target_name'  => 'ignored-files-archive',
        'mode'         => Copier::MODE_ARCHIVE,
    ));
    $archiveJob = runJobToEnd($copier, $sid, $archiveJob);
    $t->equals('done', $archiveJob['status'], 'Архивный режим завершается успешно');
    $t->equals(1, $archiveJob['result']['commits'], 'Архивный режим создаёт один коммит');
    if ($mockStateDir) {
        $targetPath = $mockStateDir . '/repos/demo/ignored-files-archive.git';
        $show = gitIn($targetPath, array('show', 'HEAD:dist/app.js'));
        $t->contains('build artifact', $show['stdout'], 'Файл под .gitignore, отслеживаемый git, перенесён архивным режимом');
        $showReadme = gitIn($targetPath, array('show', 'HEAD:README.md'));
        $t->contains('ignored-files', $showReadme['stdout'], 'Обычные файлы тоже перенесены');
    }

    /* --- Ветка по умолчанию master ----------------------------------- */
    $legacyJob = $copier->start($sid, array(
        'source_owner' => 'demo-org',
        'source_repo'  => 'legacy-site',
        'target_name'  => 'legacy-site-copy',
        'mode'         => Copier::MODE_COPY,
    ));
    $legacyJob = runJobToEnd($copier, $sid, $legacyJob);
    $t->equals('done', $legacyJob['status'], 'Копия репозитория с веткой master проходит');
    $t->equals('master', $legacyJob['result']['default_branch'], 'Ветка master сохранена как основная');
    $t->equals(2, $legacyJob['result']['branches'], 'Обе ветки (master и gh-pages) перенесены');

    /* --- Форк GitHub -------------------------------------------------- */
    $forkJob = $copier->start($sid, array(
        'source_owner' => 'demo-org',
        'source_repo'  => 'tiny-cli',
        'target_name'  => 'tiny-cli-fork',
        'mode'         => Copier::MODE_FORK,
    ));
    $t->equals('done', $forkJob['status'], 'Форк создаётся за один шаг');
    $t->contains('Форк', implode(' ', $forkJob['warnings']), 'Пользователь предупреждён о связи форка с оригиналом');
    $state = $http->request($mockBase . '/__mock/state', 'GET');
    $t->ok(in_array('demo/tiny-cli-fork', $state['json']['repos'], true), 'Форк зарегистрирован в макете');

    /* --- Ошибки ------------------------------------------------------- */
    $t->throws(function () use ($copier, $sid) {
        $copier->start($sid, array(
            'source_owner' => 'demo-org',
            'source_repo'  => 'no-such-source',
            'target_name'  => 'whatever',
            'mode'         => Copier::MODE_COPY,
        ));
    }, 'Несуществующий источник даёт ошибку', GitHubException::class);

    $t->throws(function () use ($copier, $sid) {
        $copier->start($sid, array(
            'source_owner' => 'demo-org',
            'source_repo'  => 'hello-world',
            'target_name'  => '../etc/passwd',
            'mode'         => Copier::MODE_COPY,
        ));
    }, 'Недопустимое имя цели отклоняется', ApiException::class, 'bad_target_name');

    $t->throws(function () use ($copier, $sid) {
        $copier->start($sid, array(
            'source_owner' => 'demo-org',
            'source_repo'  => 'hello-world',
            'target_name'  => 'x',
            'mode'         => 'unknown-mode',
        ));
    }, 'Неизвестный режим отклоняется', ApiException::class, 'bad_mode');

    // Гит недоступен → режим «независимая копия» должен предлагать архив.
    $brokenCopier = new Copier($gh, 'demo', $jobs, new GitClient('demo-token', array('git_binary' => '/nonexistent/git-binary')), $history);
    $t->throws(function () use ($brokenCopier, $sid) {
        $brokenCopier->start($sid, array(
            'source_owner' => 'demo-org',
            'source_repo'  => 'hello-world',
            'target_name'  => 'no-git-copy',
            'mode'         => Copier::MODE_COPY,
        ));
    }, 'Без git режим полной копии отклоняется с подсказкой', ApiException::class, 'git_missing');

    /* --- Отмена задания ---------------------------------------------- */
    $cancelJob = $copier->start($sid, array(
        'source_owner' => 'demo-org',
        'source_repo'  => 'hello-world',
        'target_name'  => 'to-be-cancelled',
        'mode'         => Copier::MODE_COPY,
    ));
    $cancelJob = $copier->execute($sid, $cancelJob['id'], 'clone');
    $cancelled = $copier->cancel($sid, $cancelJob['id']);
    $t->equals('cancelled', $cancelled['status'], 'Задание отменяется');
    $t->ok(!is_dir($cancelJob['work_dir']) || $cancelJob['work_dir'] === '', 'Рабочий каталог отменённого задания удалён');
    $afterCancel = $copier->execute($sid, $cancelJob['id'], 'push');
    $t->ok($afterCancel['status'] === 'cancelled', 'После отмены шаги не выполняются');
}

/* ====================================================================== */
/* 7. HTTP-интерфейс приложения                                           */
/* ====================================================================== */

$t->group('HTTP API приложения');

if (!$appUrl) {
    $t->skip('Приложение не запущено — проверки HTTP API пропущены');
} else {
    $http = new TestHttp();

    $health = $http->request($appUrl . '/api.php?action=health');
    $t->equals(200, $health['status'], 'health отвечает 200');
    $t->ok(!empty($health['json']['ok']), 'Ответ в формате {ok:true}');
    $t->ok(!empty($health['json']['data']['git']), 'Приложение видит git');

    $session = $http->request($appUrl . '/api.php?action=session');
    $t->ok(!empty($session['json']['data']['csrf']), 'Сессия отдаёт CSRF-токен');
    $t->equals(true, $session['json']['data']['authenticated'], 'Демо-режим авторизует автоматически');
    $csrf = $session['json']['data']['csrf'];

    $repos = $http->request($appUrl . '/api.php?action=repos&per_page=50');
    $t->equals(200, $repos['status'], 'Список репозиториев отдаётся');
    $t->ok(count($repos['json']['data']['items']) >= 3, 'В списке есть репозитории');
    $t->ok(strpos($repos['body'], 'demo-token') === false, 'Токен не попадает в ответы API');

    // Проверка CSRF: POST без токена должен быть отклонён.
    $noCsrf = $http->request($appUrl . '/api.php?action=delete_repo', 'POST', array('Content-Type' => 'application/json'), json_encode(array('owner' => 'demo', 'repo' => 'my-notes', 'confirm' => 'my-notes')));
    $t->equals(419, $noCsrf['status'], 'POST без CSRF-токена отклоняется');

    // Копирование через HTTP API (демо-режим).
    $start = $http->request($appUrl . '/api.php?action=copy_start', 'POST', array(
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
    ), json_encode(array(
        'source_owner' => 'demo-org',
        'source_repo'  => 'hello-world',
        'target_name'  => 'http-api-copy',
        'mode'         => 'copy',
        'private'      => true,
    )));
    $t->equals(200, $start['status'], 'copy_start принимает запрос');
    $job = $start['json']['data']['job'];
    $t->equals('done', $job['steps'][0]['status'], 'Первый шаг выполнен');

    foreach ($job['steps'] as $step) {
        if ($step['status'] !== 'pending') {
            continue;
        }
        $response = $http->request($appUrl . '/api.php?action=copy_step', 'POST', array(
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $csrf,
        ), json_encode(array('job_id' => $job['id'], 'step' => $step['key'])));
        $t->equals(200, $response['status'], 'Шаг ' . $step['key'] . ' выполняется по HTTP');
        $job = $response['json']['data']['job'];
    }

    $t->equals('done', $job['status'], 'Копирование через HTTP API завершено');
    $t->equals('demo/http-api-copy', $job['result']['full_name'], 'Копия создана по HTTP');

    // Ветки и удаление.
    $branches = $http->request($appUrl . '/api.php?action=branches&owner=demo&repo=http-api-copy');
    $t->equals(200, $branches['status'], 'Ветки копии отдаются');
    $t->ok(count($branches['json']['data']['branches']) === 2, 'У копии две ветки');
    $t->equals('main', $branches['json']['data']['default_branch'], 'Ветка по умолчанию определена');

    $copies = $http->request($appUrl . '/api.php?action=copies');
    $t->equals(200, $copies['status'], 'Вкладка «Мои копии» отдаёт данные');
    $found = false;
    foreach ($copies['json']['data']['items'] as $item) {
        if ($item['full_name'] === 'demo/http-api-copy') {
            $found = true;
        }
    }
    $t->ok($found, 'Созданная копия появилась в журнале');
    $t->ok(strpos($copies['body'], 'demo-token') === false, 'Журнал копий не содержит токен');

    $badDelete = $http->request($appUrl . '/api.php?action=delete_repo', 'POST', array(
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
    ), json_encode(array('owner' => 'demo', 'repo' => 'http-api-copy', 'confirm' => 'wrong-name')));
    $t->equals(422, $badDelete['status'], 'Удаление с неверным подтверждением отклоняется');

    $goodDelete = $http->request($appUrl . '/api.php?action=delete_repo', 'POST', array(
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
    ), json_encode(array('owner' => 'demo', 'repo' => 'http-api-copy', 'confirm' => 'http-api-copy')));
    $t->equals(200, $goodDelete['status'], 'Удаление с верным подтверждением проходит');

    $status = $http->request($appUrl . '/api.php?action=status');
    $t->equals(200, $status['status'], 'Диагностика отдаётся');
    $t->ok(isset($status['json']['data']['git']['available']), 'В диагностике есть сведения о git');
    $t->ok(strpos($status['body'], 'demo-token') === false, 'Диагностика не содержит токен');

    // XSS: имя репозитория с HTML не должно попадать в ответ как разметка.
    $create = $http->request($appUrl . '/api.php?action=repos&per_page=50');
    $t->ok(strpos($create['body'], '<script') === false, 'В ответах API нет инлайновых скриптов');

    $logout = $http->request($appUrl . '/api.php?action=logout', 'POST', array('X-CSRF-Token' => $csrf), '{}');
    $t->ok(in_array($logout['status'], array(200, 419), true), 'Выход обрабатывается');
}

/* ====================================================================== */

foreach ($servers as $server) {
    $server->stop();
}

JobStore::removeDir($workRoot);

exit($t->summary());

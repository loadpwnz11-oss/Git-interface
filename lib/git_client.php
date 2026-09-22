<?php
/**
 * Работа с git: клонирование, отправка веток и тегов.
 *
 * Токен доступа НИКОГДА не попадает в аргументы командной строки: служебные
 * настройки (имя коммиттера, заголовок авторизации, CA bundle) пишутся в
 * отдельный файл конфигурации (права 0600, вне веб-каталога), а git получает
 * его через `-c include.path=<файл>`. Такой способ работает и там, где
 * переменные окружения дочерним процессам не передаются (php-wasm, часть
 * хостингов с suhosin/disable_functions).
 */

namespace Ghm;

final class GitClient
{
    /** @var string|null */
    private $token;

    /** @var string */
    private $binary;

    /** @var string|null */
    private $authHost;

    /** @var array<int,string> */
    private $secrets = array();

    /** @var string|null */
    private $configFile = null;

    /** @var array<string,string>|null */
    private static $probeCache = array();

    public function __construct($token = null, array $options = array())
    {
        $options = array_merge(array(
            'git_binary' => Config::gitBinary(),
            'git_host'   => Config::gitAuthHost(),
        ), $options);

        $this->binary = (string) $options['git_binary'];
        $this->authHost = $options['git_host'];
        $this->token = $token !== null && $token !== '' ? (string) $token : null;

        if ($this->token) {
            $this->secrets[] = $this->token;
            $this->secrets[] = base64_encode('x-access-token:' . $this->token);
        }
    }

    /** @return array<int,string> */
    public function secrets()
    {
        return $this->secrets;
    }

    /** Доступен ли git на сервере (результат кэшируется). */
    public function available($refresh = false)
    {
        return $this->version($refresh) !== '';
    }

    public function version($refresh = false)
    {
        $key = $this->binary;
        if (!$refresh && isset(self::$probeCache[$key])) {
            return self::$probeCache[$key];
        }

        $cacheFile = Config::cacheDir() . '/git-probe-' . sha1($this->binary) . '.json';
        if (!$refresh && is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['version']) && isset($cached['time'])
                && (time() - (int) $cached['time']) < 3600) {
                self::$probeCache[$key] = (string) $cached['version'];

                return self::$probeCache[$key];
            }
        }

        $result = Process::run(array($this->binary, '--version'), array('timeout' => 15, 'max_output' => 4096));
        $version = trim((string) ($result['stdout'] !== '' ? $result['stdout'] : $result['stderr']));
        if ($result['rc'] !== 0) {
            $version = '';
        }

        self::$probeCache[$key] = $version;
        @file_put_contents($cacheFile, json_encode(array('version' => $version, 'time' => time())));

        return $version;
    }

    /** Окружение для запуска git (PATH/HOME/локаль, без интерактива). */
    public function env()
    {
        $env = array(
            'PATH'                => getenv('PATH') !== false ? (string) getenv('PATH') : '/usr/local/bin:/usr/bin:/bin',
            'HOME'                => getenv('HOME') !== false && getenv('HOME') !== ''
                ? (string) getenv('HOME') : Config::dataDir(),
            'LANG'                => 'C',
            'LC_ALL'              => 'C',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS'         => '',
            'SSH_ASKPASS'         => '',
            'GIT_LFS_SKIP_SMUDGE' => '1',
            'TMPDIR'              => Config::workDir(),
        );

        if (getenv('TMPDIR') !== false && getenv('TMPDIR') !== '') {
            $env['TMPDIR'] = (string) getenv('TMPDIR');
        }

        return $env;
    }

    /**
     * Путь к файлу конфигурации git со служебными настройками.
     *
     * Файл создаётся один раз на запрос и переиспользуется всеми вызовами git:
     * имя зависит от содержимого (токен/хост/CA), поэтому при смене токена
     * создаётся новый файл, а старый не мешает работе.
     */
    public function configFile()
    {
        if ($this->configFile !== null && is_file($this->configFile)) {
            return $this->configFile;
        }

        $host = $this->authHost ? rtrim($this->authHost, '/') . '/' : null;

        $fingerprint = sha1(implode("\n", array(
            (string) $this->token,
            (string) $host,
            (string) Config::get('bot_name'),
            (string) Config::get('bot_email'),
            (string) Config::caBundle(),
            Config::get('insecure_tls') ? '1' : '0',
        )));

        $file = Config::workDir() . '/git-config-' . substr($fingerprint, 0, 16) . '.conf';

        if (!is_file($file)) {
            $lines = array(
                '[user]',
                "\tname = " . self::configValue((string) Config::get('bot_name')),
                "\temail = " . self::configValue((string) Config::get('bot_email')),
                '[commit]',
                "\tgpgsign = false",
                '[core]',
                "\tquotepath = false",
            );

            if ($this->token && $host) {
                // Подсекцию с URL всегда пишем в кавычках: без них git считает
                // строку заголовка испорченной («bad config line»).
                $lines[] = '[http "' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $host) . '"]';
                $lines[] = "\textraHeader = " . self::configValue(
                    'Authorization: Basic ' . base64_encode('x-access-token:' . $this->token)
                );
            }

            $caBundle = Config::caBundle();
            if ($caBundle && is_readable($caBundle)) {
                $lines[] = '[http]';
                $lines[] = "\tsslCAInfo = " . self::configValue((string) $caBundle);
            }

            if (Config::get('insecure_tls')) {
                $lines[] = '[http]';
                $lines[] = "\tsslVerify = false";
            }

            $lines[] = '';

            $written = @file_put_contents($file, implode("\n", $lines), LOCK_EX);
            if ($written === false) {
                // Не удалось создать свой файл — работаем без него (только если
                // не нужен токен: иначе лучше явная ошибка, а не утечка в argv).
                if ($this->token) {
                    throw new \RuntimeException('Не удалось создать файл конфигурации git в ' . Config::workDir());
                }

                return null;
            }

            @chmod($file, 0600);
        }

        $this->configFile = $file;

        return $file;
    }

    /** Значение для файла конфигурации git: при необходимости в кавычках. */
    private static function configValue($value)
    {
        $value = (string) $value;

        if ($value === '' || preg_match('/^[A-Za-z0-9._\/@:+-]+$/', $value)) {
            return $value;
        }

        return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $value) . '"';
    }

    /**
     * Полная копия репозитория (все ветки и теги) в bare-репозиторий.
     *
     * @return array Результат Process::run()
     */
    public function cloneMirror($url, $targetDir, $timeout = null)
    {
        $timeout = $timeout !== null ? (int) $timeout : (int) Config::get('clone_timeout');

        return $this->run(array(
            'clone', '--bare', '--progress', '--no-single-branch',
            '--config', 'advice.detachedHead=false',
            $url, $targetDir,
        ), $timeout, null);
    }

    /** Отправка всех веток и тегов в целевой репозиторий. */
    public function pushAll($repoDir, $url, $timeout = null)
    {
        $timeout = $timeout !== null ? (int) $timeout : (int) Config::get('push_timeout');

        return $this->run(array(
            'push', '--force', '--progress', '--porcelain', $url,
            'refs/heads/*:refs/heads/*',
            'refs/tags/*:refs/tags/*',
        ), $timeout, $repoDir);
    }

    /** Отправка одной ветки (режим архива). */
    public function pushBranch($repoDir, $url, $branch, $timeout = null)
    {
        $timeout = $timeout !== null ? (int) $timeout : (int) Config::get('push_timeout');

        return $this->run(array(
            'push', '--force', '--progress', '--porcelain', $url,
            'HEAD:refs/heads/' . $branch,
        ), $timeout, $repoDir);
    }

    /** Список ссылок репозитория: ветки и теги. */
    public function refs($repoDir)
    {
        $result = $this->run(array('for-each-ref', '--format=%(refname)'), 60, $repoDir);
        $heads = array();
        $tags = array();

        foreach (preg_split('/\r?\n/', (string) $result['stdout']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (strpos($line, 'refs/heads/') === 0) {
                $heads[] = substr($line, strlen('refs/heads/'));
            } elseif (strpos($line, 'refs/tags/') === 0) {
                $tags[] = substr($line, strlen('refs/tags/'));
            }
        }

        sort($heads);
        sort($tags);

        return array('heads' => $heads, 'tags' => $tags);
    }

    /** Ветка по умолчанию в локальной копии (как её видит исходный репозиторий). */
    public function headBranch($repoDir)
    {
        $result = $this->run(array('symbolic-ref', '--short', 'HEAD'), 30, $repoDir);
        $branch = trim((string) $result['stdout']);
        if ($branch !== '') {
            return $branch;
        }

        $refs = $this->refs($repoDir);
        if (!empty($refs['heads'])) {
            return in_array('main', $refs['heads'], true) ? 'main' : $refs['heads'][0];
        }

        return '';
    }

    /** Количество коммитов во всех ветках. */
    public function commitCount($repoDir)
    {
        $result = $this->run(array('rev-list', '--count', '--all'), 60, $repoDir);

        return $result['rc'] === 0 ? (int) trim((string) $result['stdout']) : 0;
    }

    /**
     * Инициализация репозитория и первый коммит со всем содержимым каталога.
     *
     * Сообщение коммита передаётся файлом (--file=...): так в командной строке
     * не оказываются пробелы и спецсимволы, а текст может быть любым.
     */
    public function initAndCommit($workDir, $message)
    {
        $steps = array(
            array('init', '--quiet'),
            array('add', '--force', '--all', '.'),
        );

        foreach ($steps as $args) {
            $result = $this->run($args, 120, $workDir);
            if ($result['rc'] !== 0) {
                return $result;
            }
        }

        $messageFile = rtrim(dirname($workDir), '/') . '/commit-message-' . substr(sha1($workDir), 0, 8) . '.txt';
        if (@file_put_contents($messageFile, (string) $message . "\n") === false) {
            $messageFile = null;
        }

        $result = $this->run(
            $messageFile !== null
                ? array('commit', '--quiet', '--file=' . $messageFile)
                : array('commit', '--quiet', '--allow-empty-message', '--message', (string) $message),
            300,
            $workDir
        );

        if ($messageFile !== null) {
            @unlink($messageFile);
        }

        return $result;
    }

    /**
     * Произвольный вызов git в каталоге.
     *
     * Аргументы передаются массивом (shell не участвует), а рабочий каталог
     * задаётся ключом -C: на части хостингов (и в песочницах) дочерний процесс
     * не получает cwd, поэтому полагаться только на proc_open нельзя.
     */
    public function run(array $args, $timeout = 300, $cwd = null)
    {
        $command = array($this->binary);
        if ($cwd !== null && $cwd !== '') {
            $command[] = '-C';
            $command[] = $cwd;
        }

        $config = $this->configFile();
        if ($config !== null) {
            $command[] = '-c';
            $command[] = 'include.path=' . $config;
        }

        $command = array_merge($command, $args);

        $result = Process::run($command, array(
            'cwd'      => $cwd,
            'env'      => $this->env(),
            'timeout'  => $timeout,
            'secrets'  => $this->secrets,
            'fallback' => true,
        ));

        $result['timeout'] = (int) $timeout;

        return $result;
    }

    /** Человекочитаемое сообщение об ошибке git. */
    public static function errorMessage(array $result)
    {
        $text = trim((string) $result['stderr']) !== '' ? (string) $result['stderr'] : (string) $result['stdout'];

        if ($result['timed_out']) {
            return 'Превышено время ожидания операции git (' . (int) $result['timeout'] . ' сек). Репозиторий слишком большой — попробуйте режим «Быстрая копия (архив)».';
        }

        if (!empty($result['error'])) {
            return (string) $result['error'];
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text))));
        if (!$lines) {
            return 'git завершился с кодом ' . (int) $result['rc'];
        }

        $tail = array_slice($lines, -3);
        $message = implode(' ', $tail);
        $message = (string) preg_replace('/\s+/', ' ', $message);
        $message = mb_substr($message, 0, 400, 'UTF-8');

        if (stripos($message, 'authentication failed') !== false || stripos($message, 'could not read Username') !== false) {
            return 'Git не смог авторизоваться. Проверьте, что токен действует и имеет доступ к репозиторию. (' . $message . ')';
        }
        if (stripos($message, 'certificate') !== false && stripos($message, 'problem') !== false) {
            return 'Проблема с TLS-сертификатом при обращении к git: ' . $message . ' Укажите корректный CA bundle (GHM_CA_BUNDLE).';
        }
        if (stripos($message, 'not found') !== false || stripos($message, 'does not appear to be a git repository') !== false) {
            return 'Репозиторий не найден или недоступен для клонирования.';
        }
        if (stripos($message, 'remote: Repository not found') !== false) {
            return 'GitHub не отдал репозиторий: проверьте права токена на исходный репозиторий.';
        }

        return $message;
    }
}

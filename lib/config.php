<?php
/**
 * GitHub Manager — конфигурация приложения.
 *
 * Значения по умолчанию можно переопределить двумя способами:
 *   1. файлом config.local.php в корне проекта (возвращает массив);
 *   2. переменными окружения (см. таблицу ниже) — они имеют приоритет.
 *
 * | Переменная окружения   | Ключ конфига     | Назначение                                  |
 * |------------------------|------------------|---------------------------------------------|
 * | GHM_API_BASE           | api_base         | База GitHub API (для GitHub Enterprise)      |
 * | GHM_GIT_HOST           | git_host         | Хост git для авторизации (https://github.com)|
 * | GHM_GIT_BINARY         | git_binary       | Путь к бинарнику git                         |
 * | GHM_CA_BUNDLE          | ca_bundle        | Путь к bundle корневых сертификатов          |
 * | GHM_INSECURE_TLS       | insecure_tls     | 1 — отключить проверку TLS (не рекомендуется)|
 * | GHM_DEMO               | demo             | 1 — демо-режим на локальном макете GitHub    |
 * | GHM_DATA_DIR           | data_dir         | Каталог для рабочих данных (job-файлы и пр.) |
 * | GHM_CLONE_TIMEOUT      | clone_timeout    | Таймаут клонирования, сек                    |
 * | GHM_PUSH_TIMEOUT       | push_timeout     | Таймаут отправки, сек                        |
 */

namespace Ghm;

final class Config
{
    /** @var array|null */
    private static $data = null;

    /** @var string|null */
    private static $dataDir = null;

    public static function defaults()
    {
        return array(
            'app_name'          => 'GitHub Manager',
            'version'           => '2.0.0',
            'api_base'          => 'https://api.github.com',
            'git_host'          => 'https://github.com',
            'git_binary'        => 'git',
            'tar_binary'        => 'tar',
            'ca_bundle'         => null,
            'insecure_tls'      => false,
            'demo'              => false,
            'demo_login'        => 'demo',
            'demo_token'        => 'demo-token',
            'data_dir'          => null,
            'api_timeout'       => 60,
            'clone_timeout'     => 900,
            'push_timeout'      => 900,
            'archive_timeout'   => 300,
            'retries'           => 2,
            'user_agent'        => 'GitHub-Manager/2.0 (+https://github.com/loadpwnz11-oss/Git-interface)',
            'bot_name'          => 'GitHub Manager',
            'bot_email'         => 'github-manager@users.noreply.github.com',
            'history_limit'     => 200,
            'log_limit'         => 60,
        );
    }

    public static function all()
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $data = self::defaults();

        $file = dirname(__DIR__) . '/config.local.php';
        if (is_readable($file)) {
            $local = require $file;
            if (is_array($local)) {
                $data = array_merge($data, $local);
            }
        }

        $envMap = array(
            'api_base'        => 'GHM_API_BASE',
            'git_host'        => 'GHM_GIT_HOST',
            'git_binary'      => 'GHM_GIT_BINARY',
            'tar_binary'      => 'GHM_TAR_BINARY',
            'ca_bundle'       => 'GHM_CA_BUNDLE',
            'insecure_tls'    => 'GHM_INSECURE_TLS',
            'demo'            => 'GHM_DEMO',
            'demo_login'      => 'GHM_DEMO_LOGIN',
            'demo_token'      => 'GHM_DEMO_TOKEN',
            'data_dir'        => 'GHM_DATA_DIR',
            'clone_timeout'   => 'GHM_CLONE_TIMEOUT',
            'push_timeout'    => 'GHM_PUSH_TIMEOUT',
            'api_timeout'     => 'GHM_API_TIMEOUT',
            'bot_name'        => 'GHM_BOT_NAME',
            'bot_email'       => 'GHM_BOT_EMAIL',
        );

        foreach ($envMap as $key => $envName) {
            $value = getenv($envName);
            if ($value !== false && $value !== '') {
                $data[$key] = $value;
            }
        }

        $data['api_base'] = rtrim((string) $data['api_base'], '/');
        $data['git_host'] = rtrim((string) $data['git_host'], '/');
        $data['insecure_tls'] = self::toBool($data['insecure_tls']);
        $data['demo'] = self::toBool($data['demo']);
        $data['clone_timeout'] = max(30, (int) $data['clone_timeout']);
        $data['push_timeout'] = max(30, (int) $data['push_timeout']);
        $data['archive_timeout'] = max(30, (int) $data['archive_timeout']);
        $data['api_timeout'] = max(5, (int) $data['api_timeout']);

        if ($data['ca_bundle'] === null) {
            $data['ca_bundle'] = self::detectCaBundle();
        }

        self::$data = $data;

        return self::$data;
    }

    public static function get($key, $default = null)
    {
        $data = self::all();

        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /** @return void */
    public static function reset()
    {
        self::$data = null;
        self::$dataDir = null;
    }

    private static function toBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Каталог для служебных данных (job-файлы, история копий).
     * Создаётся с правами 0700, вне веб-доступа по возможности.
     */
    public static function dataDir()
    {
        if (self::$dataDir !== null) {
            return self::$dataDir;
        }

        $dir = self::get('data_dir');
        if (!$dir) {
            $dir = sys_get_temp_dir() . '/ghm-' . substr(sha1(__DIR__), 0, 8);
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        self::$dataDir = rtrim($dir, '/\\');

        return self::$dataDir;
    }

    /** Каталог для рабочих копий репозиториев (клонирование/сборка). */
    public static function workDir()
    {
        return self::ensureDir(self::dataDir() . '/work');
    }

    public static function jobsDir()
    {
        return self::ensureDir(self::dataDir() . '/jobs');
    }

    public static function historyDir()
    {
        return self::ensureDir(self::dataDir() . '/history');
    }

    public static function cacheDir()
    {
        return self::ensureDir(self::dataDir() . '/cache');
    }

    public static function ensureDir($dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /** Путь к исполняемому файлу git (может не существовать — проверяется отдельно). */
    public static function gitBinary()
    {
        return (string) self::get('git_binary', 'git');
    }

    public static function tarBinary()
    {
        return (string) self::get('tar_binary', 'tar');
    }

    /** Хост git, к которому применяется заголовок авторизации, например https://github.com/ */
    public static function gitAuthHost()
    {
        $host = self::get('git_host');

        return $host ? rtrim($host, '/') . '/' : null;
    }

    /** CA bundle для curl/openssl. */
    public static function caBundle()
    {
        return self::get('ca_bundle');
    }

    /**
     * Поиск актуального файла корневых сертификатов в типичных местах.
     *
     * @return string|null
     */
    public static function detectCaBundle()
    {
        $candidates = array();

        $ini = ini_get('curl.cainfo');
        if ($ini) {
            $candidates[] = $ini;
        }

        $ini = ini_get('openssl.cafile');
        if ($ini) {
            $candidates[] = $ini;
        }

        $env = getenv('CURL_CA_BUNDLE');
        if ($env) {
            $candidates[] = $env;
        }

        $candidates = array_merge($candidates, array(
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/ca-bundle.pem',
            '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
            '/usr/local/etc/openssl/cert.pem',
            '/etc/ssl/cert.pem',
            '/usr/local/share/certs/ca-root-nss.crt',
        ));

        foreach ($candidates as $candidate) {
            if ($candidate && is_readable($candidate) && filesize($candidate) > 0) {
                return $candidate;
            }
        }

        return null;
    }

    public static function isDemo()
    {
        return (bool) self::get('demo');
    }

    public static function version()
    {
        return (string) self::get('version');
    }

    public static function appName()
    {
        return (string) self::get('app_name');
    }
}

<?php
/**
 * Безопасность: сессии, CSRF, экранирование, валидация пользовательских данных,
 * маскирование секретов в сообщениях об ошибках.
 */

namespace Ghm;

final class Security
{
    const SESSION_TOKEN = 'ghm_token';
    const SESSION_LOGIN = 'ghm_login';
    const SESSION_USER = 'ghm_user';
    const SESSION_CSRF = 'ghm_csrf';

    /** @var bool */
    private static $sessionStarted = false;

    public static function startSession()
    {
        if (self::$sessionStarted || session_status() === PHP_SESSION_ACTIVE) {
            self::$sessionStarted = true;

            return;
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            $params = session_get_cookie_params();
            $secure = self::isHttps();

            @session_set_cookie_params(array(
                'lifetime' => 0,
                'path'     => $params['path'] ? $params['path'] : '/',
                'domain'   => $params['domain'] ? $params['domain'] : '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            @session_name('ghm_sid');
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        self::$sessionStarted = true;
    }

    public static function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        return false;
    }

    /** Текущий CSRF-токен сессии (создаётся при необходимости). */
    public static function csrfToken()
    {
        self::startSession();
        if (empty($_SESSION[self::SESSION_CSRF])) {
            $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::SESSION_CSRF];
    }

    /** @return bool */
    public static function csrfValid($token)
    {
        self::startSession();
        $known = isset($_SESSION[self::SESSION_CSRF]) ? (string) $_SESSION[self::SESSION_CSRF] : '';

        if ($known === '' || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($known, $token);
    }

    /** @return string|null */
    public static function token()
    {
        self::startSession();
        $token = isset($_SESSION[self::SESSION_TOKEN]) ? (string) $_SESSION[self::SESSION_TOKEN] : '';

        return $token !== '' ? $token : null;
    }

    /** @return string|null */
    public static function login()
    {
        self::startSession();
        $login = isset($_SESSION[self::SESSION_LOGIN]) ? (string) $_SESSION[self::SESSION_LOGIN] : '';

        return $login !== '' ? $login : null;
    }

    /** @return array|null Профиль авторизованного пользователя. */
    public static function user()
    {
        self::startSession();

        return isset($_SESSION[self::SESSION_USER]) && is_array($_SESSION[self::SESSION_USER])
            ? $_SESSION[self::SESSION_USER]
            : null;
    }

    public static function isAuthorized()
    {
        return self::token() !== null && self::login() !== null;
    }

    public static function login_user($token, array $user)
    {
        self::startSession();
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_TOKEN] = (string) $token;
        $_SESSION[self::SESSION_LOGIN] = isset($user['login']) ? (string) $user['login'] : '';
        $_SESSION[self::SESSION_USER] = self::sanitizeUser($user);
        $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));

        return $_SESSION[self::SESSION_USER];
    }

    public static function logout()
    {
        self::startSession();
        unset($_SESSION[self::SESSION_TOKEN], $_SESSION[self::SESSION_LOGIN], $_SESSION[self::SESSION_USER]);
        $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
    }

    /** Профиль без всего лишнего (и без токенов). */
    public static function sanitizeUser(array $user)
    {
        return array(
            'login'      => isset($user['login']) ? (string) $user['login'] : '',
            'name'       => isset($user['name']) ? (string) $user['name'] : '',
            'avatar_url' => isset($user['avatar_url']) ? (string) $user['avatar_url'] : '',
            'html_url'   => isset($user['html_url']) ? (string) $user['html_url'] : '',
            'public_repos' => isset($user['public_repos']) ? (int) $user['public_repos'] : 0,
            'type'       => isset($user['type']) ? (string) $user['type'] : 'User',
        );
    }

    /** Экранирование для HTML. */
    public static function e($value)
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Удаление секретов из текста (вывод git, сообщения GitHub API и т.п.).
     *
     * @param string               $text
     * @param array<int,string>    $secrets
     */
    public static function redact($text, array $secrets = array())
    {
        $text = (string) $text;

        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '' && strlen($secret) > 3) {
                $text = str_replace($secret, '***', $text);
            }
        }

        // Authorization: Basic <base64>
        $text = (string) preg_replace('/(Basic|Bearer|token)\s+[A-Za-z0-9\-_\.=:\/\+]{8,}/i', '$1 ***', $text);
        // Токены в URL: https://user:token@host
        $text = (string) preg_replace('#(https?://)[^/\s:@]+(:[^/\s@]+)?@#i', '$1***@', $text);
        // Известные форматы токенов GitHub.
        $text = (string) preg_replace('/\b(ghp|gho|ghu|ghs|ghr|github_pat)_[A-Za-z0-9_]{8,}\b/', '***', $text);

        return $text;
    }

    /**
     * Валидация логина владельца репозитория (GitHub login).
     *
     * @return bool
     */
    public static function isValidOwner($owner)
    {
        if (!is_string($owner)) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/', $owner);
    }

    /**
     * Валидация имени репозитория: буквы, цифры, точка, дефис, подчёркивание.
     *
     * @return bool
     */
    public static function isValidRepoName($name)
    {
        if (!is_string($name) || $name === '' || strlen($name) > 100) {
            return false;
        }

        if ($name === '.' || $name === '..' || $name[0] === '.') {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $name);
    }

    /**
     * Валидация имени ветки/тега для передачи в git.
     *
     * @return bool
     */
    public static function isValidRef($ref)
    {
        if (!is_string($ref) || $ref === '' || strlen($ref) > 255) {
            return false;
        }

        if (preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $ref)) {
            return false;
        }
        if (strpos($ref, '..') !== false || strpos($ref, '@{') !== false) {
            return false;
        }
        if ($ref[0] === '-' || $ref[0] === '/' || $ref[0] === '.' || substr($ref, -1) === '/') {
            return false;
        }
        if (substr($ref, -5) === '.lock' || substr($ref, -1) === '.') {
            return false;
        }
        if (strpos($ref, '//') !== false) {
            return false;
        }

        return true;
    }

    /** @return bool */
    public static function isValidJobId($id)
    {
        return is_string($id) && (bool) preg_match('/^[a-f0-9]{32}$/', $id);
    }

    /** @return bool */
    public static function isValidSha($sha)
    {
        return is_string($sha) && (bool) preg_match('/^[a-f0-9]{7,40}$/i', $sha);
    }

    /**
     * Приведение пользовательского имени репозитория к допустимому виду.
     */
    public static function slugifyRepoName($value)
    {
        $value = strtolower(trim((string) $value));
        $value = (string) preg_replace('/[^a-z0-9._-]+/', '-', $value);
        $value = trim($value, '-._');

        return substr($value, 0, 100);
    }
}

<?php
/**
 * Запуск внешних процессов (git, tar) без интерактивной оболочки.
 *
 * Основной путь — proc_open() с массивом аргументов: код возврата и вывод
 * доступны точно, а аргументы не попадают в интерпретатор команд. Исключение —
 * php-wasm (см. isWasm()): там аргументы собираются в строку и передаются в
 * /bin/sh, поэтому они квотируются самостоятельно (shellQuote()).
 * Если proc_open недоступен (запрещён хостингом) — запасной путь через exec()
 * с escapeshellarg().
 */

namespace Ghm;

final class Process
{
    /** @var bool|null */
    private static $arraySupport = null;

    /** @var bool|null */
    private static $wasm = null;

    /**
     * Работает ли код внутри WebAssembly (php-wasm).
     *
     * Там proc_open() собирает строку команды и передаёт её в /bin/sh, поэтому
     * аргументы с пробелами и спецсимволами нужно квотировать самим. На обычном
     * PHP-хостинге массив аргументов передаётся напрямую, без shell.
     */
    public static function isWasm()
    {
        if (self::$wasm === null) {
            self::$wasm = (stripos(PHP_BINARY, 'php-wasm') !== false)
                || (stripos(@php_uname('s'), 'emscripten') !== false);
        }

        return self::$wasm;
    }

    /** Экранирование аргумента для POSIX sh (локаль-независимое, байтовое). */
    public static function shellQuote($argument)
    {
        return "'" . str_replace("'", "'\\''", (string) $argument) . "'";
    }

    /**
     * Аргументы в виде, пригодном для передачи в proc_open().
     *
     * На обычном PHP это исходный массив. В wasm — одна строка с корректно
     * заквотированными аргументами, чтобы пробелы и «|», «;», «$» не ломали команду.
     */
    private static function prepareCommand(array $command)
    {
        if (!self::isWasm()) {
            return $command;
        }

        $quoted = array();
        foreach ($command as $argument) {
            $quoted[] = self::shellQuote($argument);
        }

        return array(implode(' ', $quoted));
    }

    /**
     * @param array $command Массив аргументов, например ['git', 'clone', $url, $dir].
     * @param array $options cwd, env, timeout, max_output, stdin, secrets
     *
     * @return array{ok:bool,rc:int,stdout:string,stderr:string,duration:float,timed_out:bool,command:string,error:string}
     */
    public static function run(array $command, array $options = array())
    {
        $options = array_merge(array(
            'cwd'        => null,
            'env'        => null,
            'timeout'    => 300,
            'max_output' => 262144,
            'stdin'      => null,
            'secrets'    => array(),
            'fallback'   => true,
        ), $options);

        $started = microtime(true);
        $result = null;

        if (self::supportsArrayProcOpen()) {
            $result = self::runWithProcOpen($command, $options);
        }

        if ($result === null && $options['fallback']) {
            $result = self::runWithExec($command, $options);
        }

        if ($result === null) {
            $result = array(
                'ok'        => false,
                'rc'        => -1,
                'stdout'    => '',
                'stderr'    => '',
                'timed_out' => false,
                'error'     => 'Не удалось запустить процесс: proc_open() и exec() недоступны на этом хостинге.',
            );
        }

        $result['stdout'] = Security::redact(self::tail($result['stdout'], $options['max_output']), $options['secrets']);
        $result['stderr'] = Security::redact(self::tail($result['stderr'], $options['max_output']), $options['secrets']);
        $result['command'] = self::maskedCommand($command, $options['secrets']);
        $result['duration'] = round(microtime(true) - $started, 3);
        if (!isset($result['error'])) {
            $result['error'] = '';
        }
        $result['ok'] = ($result['rc'] === 0 && !$result['timed_out'] && $result['error'] === '');

        return $result;
    }

    /** Есть ли быстрый и надёжный способ запуска процесса. */
    public static function supportsArrayProcOpen()
    {
        if (self::$arraySupport !== null) {
            return self::$arraySupport;
        }

        self::$arraySupport = function_exists('proc_open')
            && PHP_VERSION_ID >= 70400
            && !self::isDisabled('proc_open');

        return self::$arraySupport;
    }

    public static function isDisabled($function)
    {
        $disabled = (string) ini_get('disable_functions');

        if ($disabled === '') {
            return false;
        }

        $list = array_map('trim', explode(',', strtolower($disabled)));

        return in_array(strtolower($function), $list, true);
    }

    /** Результат «команда не найдена» / «не удалось запустить». */
    private static function runWithProcOpen(array $command, array $options)
    {
        if (!function_exists('proc_open')) {
            return null;
        }

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $pipes = array();
        $proc = @proc_open(self::prepareCommand($command), $descriptors, $pipes, $options['cwd'], $options['env']);

        if (!is_resource($proc)) {
            return null;
        }

        if ($options['stdin'] !== null) {
            @fwrite($pipes[0], (string) $options['stdin']);
        }
        @fclose($pipes[0]);

        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = microtime(true) + (float) $options['timeout'];
        $limit = (int) $options['max_output'] * 4;

        while (true) {
            $read = array($pipes[1], $pipes[2]);
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);

            if ($ready > 0) {
                foreach ($read as $stream) {
                    $chunk = @fread($stream, 65536);
                    if (is_string($chunk) && $chunk !== '') {
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
                if (strlen($stdout) > $limit) {
                    $stdout = self::tail($stdout, $limit);
                }
                if (strlen($stderr) > $limit) {
                    $stderr = self::tail($stderr, $limit);
                }
            }

            $status = @proc_get_status($proc);
            if (!$status || empty($status['running'])) {
                break;
            }

            if (microtime(true) > $deadline) {
                $timedOut = true;
                self::terminate($proc);
                break;
            }
        }

        $stdout .= (string) @stream_get_contents($pipes[1]);
        $stderr .= (string) @stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);

        $rc = proc_close($proc);
        if (isset($status['exitcode']) && is_int($status['exitcode']) && $status['exitcode'] !== -1) {
            $rc = $status['exitcode'];
        }

        return array(
            'ok'        => false,
            'rc'        => (int) $rc,
            'stdout'    => (string) $stdout,
            'stderr'    => (string) $stderr,
            'timed_out' => $timedOut,
            'error'     => '',
        );
    }

    private static function terminate($proc)
    {
        @proc_terminate($proc, 15);
        usleep(200000);
        $status = @proc_get_status($proc);
        if ($status && !empty($status['running'])) {
            @proc_terminate($proc, 9);
        }
    }

    private static function runWithExec(array $command, array $options)
    {
        if (!function_exists('exec') || self::isDisabled('exec')) {
            return null;
        }

        $parts = array();
        foreach ($command as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }

        $line = implode(' ', $parts);
        if ($options['cwd']) {
            $line = 'cd ' . escapeshellarg($options['cwd']) . ' && ' . $line;
        }
        $line .= ' 2>&1';

        if ($options['env'] && function_exists('putenv')) {
            $restore = array();
            foreach ($options['env'] as $key => $value) {
                $restore[$key] = getenv($key);
                @putenv($key . '=' . $value);
            }
        } else {
            $restore = array();
        }

        $output = array();
        $rc = 0;
        @exec($line, $output, $rc);

        foreach ($restore as $key => $value) {
            @putenv($key . '=' . ($value === false ? '' : $value));
        }

        return array(
            'ok'        => false,
            'rc'        => (int) $rc,
            'stdout'    => implode("\n", $output),
            'stderr'    => '',
            'timed_out' => false,
            'error'     => '',
        );
    }

    private static function tail($text, $limit)
    {
        $text = (string) $text;
        $limit = (int) $limit;

        if ($limit <= 0 || strlen($text) <= $limit) {
            return $text;
        }

        return '…' . substr($text, -$limit);
    }

    private static function maskedCommand(array $command, array $secrets = array())
    {
        $parts = array();
        foreach ($command as $arg) {
            $arg = (string) $arg;
            if (strlen($arg) > 200) {
                $arg = substr($arg, 0, 200) . '…';
            }
            $parts[] = $arg;
        }

        return Security::redact(implode(' ', $parts), $secrets);
    }

    /** Доступна ли указанная программа. */
    public static function hasBinary($binary, $args = array('--version'), $timeout = 15)
    {
        $result = self::run(array_merge(array($binary), $args), array('timeout' => $timeout, 'max_output' => 4096));

        return $result['rc'] === 0;
    }

    /** Первая строка версии программы (для диагностики). */
    public static function version($binary, $args = array('--version'), $timeout = 15)
    {
        $result = self::run(array_merge(array($binary), $args), array('timeout' => $timeout, 'max_output' => 4096));
        $line = trim((string) strtok($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'], "\n"));

        return $result['rc'] === 0 ? $line : '';
    }
}

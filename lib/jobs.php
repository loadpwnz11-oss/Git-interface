<?php
/**
 * Хранилище заданий копирования.
 *
 * Задание — это JSON-файл в защищённом каталоге. Файл привязан к сессии,
 * поэтому чужое задание нельзя ни прочитать, ни продолжить.
 */

namespace Ghm;

final class JobStore
{
    /** @var string */
    private $dir;

    public function __construct($dir = null)
    {
        $this->dir = $dir !== null ? $dir : Config::jobsDir();
        Config::ensureDir($this->dir);
    }

    public function dir()
    {
        return $this->dir;
    }

    /**
     * @param array $job Черновик задания (без id/служебных полей)
     *
     * @return array Полное задание
     */
    public function create(array $job)
    {
        $job['id'] = bin2hex(random_bytes(16));
        $job['created_at'] = time();
        $job['updated_at'] = time();
        $job['status'] = 'pending';
        $job['log'] = array();
        $job['error'] = null;

        $this->save($job);

        return $job;
    }

    /**
     * @return array|null null, если задание не найдено или не принадлежит сессии
     */
    public function load($id, $sid = null)
    {
        if (!Security::isValidJobId($id)) {
            return null;
        }

        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $job = json_decode($raw, true);
        if (!is_array($job) || !isset($job['id'])) {
            return null;
        }

        if ($sid !== null) {
            $expected = isset($job['sid']) ? (string) $job['sid'] : '';
            if ($expected === '' || !hash_equals($expected, $this->sessionKey($sid))) {
                return null;
            }
        }

        return $job;
    }

    public function save(array $job)
    {
        $job['updated_at'] = time();
        $path = $this->path($job['id']);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $encoded = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($tmp, $encoded) === false) {
            throw new ApiException('Не удалось сохранить состояние задания.', 500, 'job_storage_failed');
        }

        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new ApiException('Не удалось сохранить состояние задания.', 500, 'job_storage_failed');
        }
    }

    public function remove($id)
    {
        if (!Security::isValidJobId($id)) {
            return;
        }

        @unlink($this->path($id));
        @unlink($this->dir . '/' . $id . '.lock');
    }

    /** Ключ сессии, который попадает в задание (сам session_id не хранится). */
    public function sessionKey($sid)
    {
        return hash('sha256', 'ghm-job:' . (string) $sid);
    }

    /**
     * Выполнить действие под эксклюзивной блокировкой задания
     * (защита от двойного нажатия кнопки).
     *
     * @return mixed Результат callback
     */
    public function withLock($id, $sid, callable $callback)
    {
        if (!Security::isValidJobId($id)) {
            throw new ApiException('Некорректный идентификатор задания.', 400, 'bad_job_id');
        }

        $lockPath = $this->dir . '/' . $id . '.lock';
        $handle = @fopen($lockPath, 'c');
        if (!$handle) {
            throw new ApiException('Не удалось создать блокировку задания.', 500, 'job_lock_failed');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new ApiException('Операция уже выполняется, подождите.', 409, 'job_busy');
        }

        try {
            $job = $this->load($id, $sid);
            if ($job === null) {
                throw new ApiException('Задание не найдено или устарело.', 404, 'job_not_found');
            }

            return $callback($job);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Добавление строки в лог задания. */
    public function log(array &$job, $line)
    {
        $line = trim((string) $line);
        if ($line === '') {
            return;
        }

        $job['log'][] = array('t' => time(), 'line' => mb_substr($line, 0, 400, 'UTF-8'));
        $limit = (int) Config::get('log_limit', 60);
        if (count($job['log']) > $limit) {
            $job['log'] = array_slice($job['log'], -$limit);
        }
    }

    /** Найти шаг задания по ключу. */
    public function stepIndex(array $job, $key)
    {
        foreach ($job['steps'] as $index => $step) {
            if ($step['key'] === $key) {
                return $index;
            }
        }

        return -1;
    }

    /** Обновить шаг (по ссылке через задание). */
    public function updateStep(array &$job, $key, array $changes)
    {
        $index = $this->stepIndex($job, $key);
        if ($index < 0) {
            return;
        }

        $job['steps'][$index] = array_merge($job['steps'][$index], $changes);
    }

    public function step(array $job, $key)
    {
        $index = $this->stepIndex($job, $key);

        return $index >= 0 ? $job['steps'][$index] : null;
    }

    /**
     * Удаление старых заданий и «осиротевших» рабочих каталогов.
     *
     * @return int Количество удалённых заданий
     */
    public function gc($maxAge = 86400, $workAgeSeconds = 21600)
    {
        $removed = 0;
        $now = time();

        foreach ((array) glob($this->dir . '/*.json') as $file) {
            $job = json_decode((string) @file_get_contents($file), true);
            $updated = is_array($job) && isset($job['updated_at']) ? (int) $job['updated_at'] : 0;

            if ($updated > 0 && ($now - $updated) < $maxAge) {
                continue;
            }

            if (is_array($job) && !empty($job['work_dir']) && is_dir($job['work_dir'])) {
                self::removeDir($job['work_dir']);
            }
            @unlink($file);
            $removed++;
        }

        foreach ((array) glob($this->dir . '/*.lock') as $file) {
            if (@filemtime($file) !== false && ($now - @filemtime($file)) > $maxAge) {
                @unlink($file);
            }
        }

        foreach ((array) glob(Config::workDir() . '/*') as $dir) {
            if (is_dir($dir) && (@filemtime($dir) !== false) && ($now - @filemtime($dir)) > $workAgeSeconds) {
                self::removeDir($dir);
            }
        }

        return $removed;
    }

    /** Рекурсивное удаление каталога (без внешних команд). */
    public static function removeDir($dir)
    {
        $dir = (string) $dir;
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        // Страховка: удаляем только внутри рабочего каталога приложения.
        $real = realpath($dir);
        $root = realpath(Config::dataDir());
        if ($real === false || $root === false || strpos($real, $root) !== 0) {
            return;
        }

        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private function path($id)
    {
        return $this->dir . '/' . $id . '.json';
    }
}

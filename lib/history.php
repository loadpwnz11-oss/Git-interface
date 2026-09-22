<?php
/**
 * Журнал копий, созданных через веб-интерфейс.
 * GitHub не различает «независимую копию» и обычный репозиторий,
 * поэтому свои операции приложение помнит само.
 */

namespace Ghm;

final class History
{
    /** @var string */
    private $dir;

    public function __construct($dir = null)
    {
        $this->dir = $dir !== null ? $dir : Config::historyDir();
        Config::ensureDir($this->dir);
    }

    /** @return array */
    public function add($login, array $entry)
    {
        $login = (string) $login;
        if ($login === '') {
            return array();
        }

        $entry = array_merge(array(
            'full_name'     => '',
            'html_url'      => '',
            'source'        => '',
            'source_url'    => '',
            'mode'          => Copier::MODE_COPY,
            'private'       => false,
            'default_branch' => '',
            'branches'      => 0,
            'commits'       => 0,
            'size_human'    => '',
            'created_at'    => time(),
        ), $entry);

        $data = $this->read($login);
        $entries = array();
        foreach ($data['entries'] as $existing) {
            if (isset($existing['full_name']) && $existing['full_name'] === $entry['full_name']) {
                continue;
            }
            $entries[] = $existing;
        }
        array_unshift($entries, $entry);
        $entries = array_slice($entries, 0, (int) Config::get('history_limit', 200));

        $this->write($login, array('login' => $login, 'updated_at' => time(), 'entries' => $entries));

        return $entry;
    }

    /** @return array<int,array> */
    public function all($login)
    {
        $data = $this->read((string) $login);

        return $data['entries'];
    }

    public function find($login, $fullName)
    {
        foreach ($this->all($login) as $entry) {
            if (isset($entry['full_name']) && strcasecmp((string) $entry['full_name'], (string) $fullName) === 0) {
                return $entry;
            }
        }

        return null;
    }

    public function remove($login, $fullName)
    {
        $login = (string) $login;
        $data = $this->read($login);
        $entries = array();
        foreach ($data['entries'] as $entry) {
            if (isset($entry['full_name']) && strcasecmp((string) $entry['full_name'], (string) $fullName) === 0) {
                continue;
            }
            $entries[] = $entry;
        }
        $this->write($login, array('login' => $login, 'updated_at' => time(), 'entries' => $entries));
    }

    /** Множество full_name для быстрой проверки «это наша копия». */
    public function namesMap($login)
    {
        $map = array();
        foreach ($this->all($login) as $entry) {
            if (!empty($entry['full_name'])) {
                $map[strtolower((string) $entry['full_name'])] = $entry;
            }
        }

        return $map;
    }

    /** @return array */
    private function read($login)
    {
        $file = $this->path($login);
        if (!is_file($file)) {
            return array('login' => $login, 'entries' => array());
        }

        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            return array('login' => $login, 'entries' => array());
        }

        return $data;
    }

    private function write($login, array $data)
    {
        $file = $this->path($login);
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
            }
        }
    }

    private function path($login)
    {
        return $this->dir . '/' . sha1('ghm-history:' . strtolower((string) $login)) . '.json';
    }
}

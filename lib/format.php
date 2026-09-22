<?php
/**
 * Помощники форматирования для интерфейса.
 */

namespace Ghm;

final class Format
{
    /** @var array<string,string> */
    private static $languageColors = array(
        'JavaScript'   => '#f1e05a',
        'TypeScript'   => '#3178c6',
        'Python'       => '#3572A5',
        'Java'         => '#b07219',
        'PHP'          => '#4F5D95',
        'HTML'         => '#e34c26',
        'CSS'          => '#563d7c',
        'SCSS'         => '#c6538c',
        'Ruby'         => '#701516',
        'Go'           => '#00ADD8',
        'Rust'         => '#dea584',
        'C'            => '#555555',
        'C++'          => '#f34b7d',
        'C#'           => '#178600',
        'Shell'        => '#89e051',
        'Swift'        => '#F05138',
        'Kotlin'       => '#A97BFF',
        'Dart'         => '#00B4AB',
        'Scala'        => '#c22d40',
        'Elixir'       => '#6e4a7e',
        'Erlang'       => '#B83998',
        'Haskell'      => '#5e5086',
        'Lua'          => '#000080',
        'Perl'         => '#0298c3',
        'R'            => '#198CE7',
        'Vue'          => '#41b883',
        'Svelte'       => '#ff3e00',
        'Objective-C'  => '#438eff',
        'Groovy'       => '#4298b8',
        'Clojure'      => '#db5855',
        'CoffeeScript' => '#244776',
        'Jupyter Notebook' => '#DA5B0B',
        'PowerShell'   => '#012456',
        'Assembly'     => '#6E4C13',
        'Dockerfile'   => '#384d54',
        'Makefile'     => '#427819',
        'TeX'          => '#3D6117',
        'Nix'          => '#7e7eff',
        'Zig'          => '#ec915c',
        'Julia'        => '#a270ba',
        'MATLAB'       => '#e16737',
        'PLSQL'        => '#dad8d8',
        'SQLPL'        => '#e38c00',
        'Vim Script'   => '#199f4b',
        'Batchfile'    => '#C1F12E',
        'ASP.NET'      => '#9400ff',
        'Blade'        => '#f7523f',
        'Hack'         => '#878787',
        'Solidity'     => '#AA6746',
        'GDScript'     => '#355570',
        'Pascal'       => '#E3F171',
        'Fortran'      => '#4d41b1',
        'Ada'          => '#02f88c',
        'COBOL'        => '#0073ff',
        'F#'           => '#b845fc',
        'OCaml'        => '#3be133',
        'Crystal'      => '#000100',
        'Nim'          => '#ffc200',
        'WebAssembly'  => '#04133b',
        'Json'         => '#292929',
        'YAML'         => '#cb171e',
        'Markdown'     => '#083fa1',
        'XML'          => '#0060ac',
        'Astro'        => '#ff5a03',
        'MDX'          => '#fcb32c',
        'Bicep'        => '#519aba',
        'Cython'       => '#fedf5b',
        'FreeMarker'   => '#0050b2',
        'QML'          => '#44a51c',
        'Fluent'       => '#ffcc33',
        'Odin'         => '#60AFFE',
        'Raku'         => '#0000fb',
        'Roff'         => '#ecdebe',
        'Standard ML'  => '#dc566d',
        'Tcl'          => '#e4cc98',
        'Vala'         => '#a56de2',
        'Verilog'      => '#b2b7f8',
        'VHDL'         => '#adb2cb',
        'XSLT'         => '#EB8CEB',
        'Zsh'          => '#89e051',
        'Awk'          => '#c30e9b',
        'AutoHotkey'   => '#6594b9',
        'Apex'         => '#1797c0',
        'Apex Class'   => '#1797c0',
        '1C Enterprise' => '#814CCC',
    );

    /** Размер в килобайтах → человекочитаемая строка. */
    public static function bytes($sizeKb)
    {
        $bytes = (float) $sizeKb * 1024;

        if ($bytes <= 0) {
            return '0 КБ';
        }

        if ($bytes < 1024) {
            return round($bytes) . ' Б';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' КБ';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1) . ' МБ';
        }

        return round($bytes / 1024 / 1024 / 1024, 2) . ' ГБ';
    }

    /** @return string */
    public static function timeAgo($timestamp)
    {
        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            return '';
        }

        $diff = time() - $timestamp;
        if ($diff < 60) {
            return 'только что';
        }
        if ($diff < 3600) {
            $minutes = (int) floor($diff / 60);

            return $minutes . ' ' . self::plural($minutes, 'минуту', 'минуты', 'минут') . ' назад';
        }
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);

            return $hours . ' ' . self::plural($hours, 'час', 'часа', 'часов') . ' назад';
        }
        if ($diff < 2592000) {
            $days = (int) floor($diff / 86400);

            return $days . ' ' . self::plural($days, 'день', 'дня', 'дней') . ' назад';
        }

        return date('d.m.Y', $timestamp);
    }

    public static function dateTime($timestamp)
    {
        return (int) $timestamp > 0 ? date('d.m.Y H:i', (int) $timestamp) : '—';
    }

    /** Склонение существительных: plural(5, 'файл', 'файла', 'файлов'). */
    public static function plural($count, $one, $few, $many)
    {
        $count = abs((int) $count) % 100;
        $last = $count % 10;

        if ($count > 10 && $count < 20) {
            return $many;
        }
        if ($last > 1 && $last < 5) {
            return $few;
        }
        if ($last === 1) {
            return $one;
        }

        return $many;
    }

    /** Цвет для языка программирования. */
    public static function languageColor($language)
    {
        $language = (string) $language;
        if ($language === '') {
            return '#8b949e';
        }

        if (isset(self::$languageColors[$language])) {
            return self::$languageColors[$language];
        }

        return self::colorFromString($language);
    }

    /** Детерминированный цвет из строки (для неизвестных языков). */
    public static function colorFromString($value)
    {
        $hash = crc32(strtolower((string) $value));
        $hue = $hash % 360;

        return self::hslToHex($hue / 360, 0.55, 0.45);
    }

    private static function hslToHex($h, $s, $l)
    {
        $r = $l;
        $g = $l;
        $b = $l;
        $v = ($l <= 0.5) ? ($l * (1.0 + $s)) : ($l + $s - $l * $s);
        if ($v > 0) {
            $m = $l + $l - $v;
            $sv = ($v - $m) / $v;
            $h *= 6.0;
            $sextant = (int) floor($h);
            $fract = $h - $sextant;
            $vsf = $v * $sv * $fract;
            $mid1 = $m + $vsf;
            $mid2 = $v - $vsf;

            switch ($sextant) {
                case 0:
                    $r = $v; $g = $mid1; $b = $m;
                    break;
                case 1:
                    $r = $mid2; $g = $v; $b = $m;
                    break;
                case 2:
                    $r = $m; $g = $v; $b = $mid1;
                    break;
                case 3:
                    $r = $m; $g = $mid2; $b = $v;
                    break;
                case 4:
                    $r = $mid1; $g = $m; $b = $v;
                    break;
                case 5:
                    $r = $v; $g = $m; $b = $mid2;
                    break;
            }
        }

        return sprintf('#%02x%02x%02x', (int) round($r * 255), (int) round($g * 255), (int) round($b * 255));
    }

    /** Короткое представление числа: 1200 → 1.2k. */
    public static function compactNumber($value)
    {
        $value = (int) $value;
        if ($value < 1000) {
            return (string) $value;
        }
        if ($value < 1000000) {
            return rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.') . 'k';
        }

        return rtrim(rtrim(number_format($value / 1000000, 1, '.', ''), '0'), '.') . 'M';
    }
}

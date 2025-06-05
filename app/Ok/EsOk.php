<?php

namespace App\Ok;

class EsOk
{

    public static function ok(string $string): array|string|null
    {
        $string = str_replace('\\', '\\\\', $string);
        $string = str_replace('/', '\/', $string);
        $string = preg_replace('/\s+AND\s+/mu', ' and ', $string);
        $string = preg_replace('/\s+OR\s+/mu', ' or ', $string);
        $string = preg_replace('/\s+TO\s+/mu', ' to ', $string);
        $string = preg_replace('/\s+NOT\s+/mu', ' not ', $string);
        $string = str_replace('+', '\+', $string);
        $string = str_replace('-', '\-', $string);
        $string = str_replace('=', '\=', $string);
        $string = str_replace('&&', '\&\&', $string);
        $string = str_replace('||', '\|\|', $string);
        $string = str_replace('>', '\>', $string);
        $string = str_replace('<', '\<', $string);
        $string = str_replace('!', '\!', $string);
        $string = str_replace('(', '\(', $string);
        $string = str_replace(')', '\)', $string);
        $string = str_replace('{', '\{', $string);
        $string = str_replace('}', '\}', $string);
        $string = str_replace('[', '\[', $string);
        $string = str_replace(']', '\]', $string);
        $string = str_replace('^', '\^', $string);
        $string = str_replace('"', '\"', $string);
        $string = str_replace('~', '\~', $string);
        $string = str_replace('*', '\*', $string);
        $string = str_replace('?', '\?', $string);
        return str_replace(':', '\:', $string);
    }
}

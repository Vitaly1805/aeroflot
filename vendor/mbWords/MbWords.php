<?php

namespace vendor;

class MbWords
{
    public function mb_ucfirst($str, $encoding='UTF-8')
    {
        if (!function_exists('mb_ucfirst') && extension_loaded('mbstring'))
        {
            $str = mb_ereg_replace('^[\ ]+', '', $str);
            $str = mb_strtoupper(mb_substr($str, 0, 1, $encoding), $encoding) .
                mb_substr($str, 1, mb_strlen($str), $encoding);
            return $str;
        }
    }
}
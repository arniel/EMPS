<?php

function emps_microtime_float($microtime)
{
    list($usec, $sec) = explode(" ", $microtime);
    return ((float)$usec + (float)$sec);
}

function dump($var)
{
    echo "<pre>";
    var_dump($var);
    echo "</pre>";
}

function format_size($bytes)
{
    if ($bytes <= 0) return $bytes;
    $formats = array("%d байт", "%.1f Кб", "%.1f Мб", "%.1f Гб", "%.1f Тб");
    $logsize = min((int)(log($bytes) / log(1024)), count($formats) - 1);
    return sprintf($formats[$logsize], $bytes / pow(1024, $logsize));
}

?>
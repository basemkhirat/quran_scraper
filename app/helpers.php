<?php

/**
 * Get String between two strings
 * @param string $string the sentence
 * @param string $start the start keyword
 * @param string $end the end keyword
 *
 * @return string
 */
function get_string_between($string, $start, $end)
{
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    return substr($string, $ini, $len);
}

/**
 * Download a file from URL
 * @param string $url the url
 * @param string $referer the referer url
 *
 * @return string
 */
function fetchUrl($url, $referer = false, $headers = [])
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_REFERER, $referer);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Safari/537.36");
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_URL, $url);

    $curl_headers = [];

    foreach ($headers as $key => $val) {
        $curl_headers[] = $key . ': ' . $val;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode == 404) {
        return false;
    }

    return $response;
}

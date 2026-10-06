<?php

namespace QaHttp;

final class Http
{
    /**
     * @return array [status, rawBody, json|null, responseHeaders] responseHeaders: nama header
     *   (huruf kecil) => nilai (header terakhir menang bila diulang, mis. sesudah redirect)
     */
    public static function request($method, $uri, $token = null, $body = null, $timeout = 120)
    {
        $url = self::url($uri);
        $headers = ['Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HEADERFUNCTION => function ($curlHandle, $line) use (&$responseHeaders) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);

        $raw = curl_exec($ch);
        $error = $raw === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new InfraError(Config::redact(strtoupper($method) . ' ' . self::path($url) . ' gagal: ' . $error));
        }

        return [$status, $raw, json_decode($raw, true), $responseHeaders];
    }

    /**
     * POST multipart (unggah berkas), mis. import Excel. `$filePath` = path lokal berkas.
     *
     * @return array [status, rawBody, json|null]
     */
    public static function uploadFile($uri, $token, $filePath, $fieldName = 'file', $extraFields = [])
    {
        $url = self::url($uri);
        $headers = ['Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $mime = function_exists('mime_content_type') ? (mime_content_type($filePath) ?: 'application/octet-stream') : 'application/octet-stream';
        $post = $extraFields;
        $post[$fieldName] = new \CURLFile($filePath, $mime, basename($filePath));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
        ]);

        $raw = curl_exec($ch);
        $error = $raw === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new InfraError(Config::redact('POST ' . self::path($url) . ' (upload) gagal: ' . $error));
        }

        return [$status, $raw, json_decode($raw, true)];
    }

    /** URL tanpa query string, untuk pesan (query bisa memuat access_key). */
    public static function path($uri)
    {
        $path = preg_replace('#^https?://[^/]+#', '', $uri);

        return strtok($path, '?');
    }

    private static function url($uri)
    {
        return preg_match('#^https?://#', $uri) ? $uri : Config::base() . '/' . ltrim($uri, '/');
    }
}

<?php

declare(strict_types=1);

final class HttpClient
{
    public static function getText(string $url, int $timeout = 20, array $headers = []): string
    {
        return self::request('GET', $url, null, $timeout, $headers);
    }

    public static function getJson(string $url, int $timeout = 12, array $headers = []): array
    {
        $body = self::getText($url, $timeout, $headers);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid JSON from ' . $url);
        }
        return $json;
    }

    public static function postJson(string $url, array $payload, int $timeout = 60, array $headers = []): array
    {
        $body = self::request('POST', $url, json_encode($payload, JSON_UNESCAPED_SLASHES), $timeout, $headers);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid JSON from ' . $url);
        }
        return $json;
    }

    public static function request(string $method, string $url, ?string $body, int $timeout, array $headers = []): string
    {
        $httpHeaders = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $httpHeaders,
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($result === false || $status >= 400) {
            $detail = $error !== '' ? $error : (is_string($result) ? substr($result, 0, 500) : 'No response body');
            throw new RuntimeException(sprintf('%s %s failed (%s): %s', $method, $url, $status ?: 'curl', $detail));
        }

        return (string) $result;
    }
}

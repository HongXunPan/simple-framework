<?php

/**
 *
 * Created by PhpStorm At 2022/7/11 16:54.
 * Author: HongXunPan
 * Email: me@kangxuanpeng.com
 */

declare(strict_types=1);

namespace HongXunPan\Framework\Core;

class Request
{
    use PropertyTrait;

    public array $query;
    public array $request;
    public array $cookie;
    public array $server;
    public array $files;
    public array $headers;
    public array $commons = [];
    public string $ip;
    public string $uri = '';
    public string $host = '';
    public float $microTime;
    public int $startMemory;
    public string $requestId;
    public ?int $user_id = null;
    public mixed $user = null;

    public function __construct($request = [])
    {
        $this->requestId = date('md') . uniqid();
        if (app()->isCli) {
            $request = ['empty' => true];
        }
        if (!empty($request)) {
            $this->server = $request['server'] ?? [];
            $this->ip = $request['ip'] ?? '';
            $this->headers = $request['headers'] ?? [];
            $this->query = $request['get'] ?? [];
            if (isset($this->headers['CONTENT_TYPE']) && $this->headers['CONTENT_TYPE'] == 'application/json') {
                $this->request = json_decode($request['rawContent'], true) ?? [];
            } else {
                $this->request = $request['post'] ?? [];
            }
            $this->cookie = $request['cookie'] ?? [];
            $this->files = $request['files'] ?? [];
            $this->uri = $request['server']['request_uri'] ?? '';
            $this->host = $request['headers']['host'] ?? '';
            $this->microTime = microtime(true);
            $this->startMemory = memory_get_usage();
            return;
        }
        ob_start();
        $this->server = $_SERVER;
        $this->getIp();
        $this->headers = $this->getHeaders();
        $this->query = $_GET;
        if (isset($this->headers['CONTENT_TYPE']) && $this->headers['CONTENT_TYPE'] == 'application/json') {
            $this->request = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            $this->request = $_POST;
        }
        $this->cookie = $_COOKIE;
        $this->files = $_FILES;
        $this->uri = explode('?', $this->server['REQUEST_URI'])[0];
        $this->host = $this->server['SERVER_NAME'];
        $this->microTime = defined('START_MICRO_TIME') ? START_MICRO_TIME : microtime(true);
        $this->startMemory = defined('START_MEMORY') ? START_MEMORY : memory_get_usage();
        ob_end_clean();
        ob_clean();
    }

    public function getIp(): string
    {
        if (isset($this->ip)) {
            return $this->ip;
        }
        $ip = '';
        $ip = $_SERVER['HTTP_X_TRUE_IP'] ?? $ip;
        $ip = $_SERVER['HTTP_WL_PROXY_CLIENT_IP'] ?? $ip;
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $ip;
        $ip = $_SERVER['HTTP_CLIENT_IP'] ?? $ip;
        $ip = $_SERVER['REMOTE_ADDR'] ?? $ip;

        if (strpos($ip, ',')) {//有逗号的情况，变成数组取最后一个
            $arr = explode(',', $ip);
            $ip = end($arr);
        }
        $this->ip = trim($ip);
        return $this->ip;
    }

    public function getHeaders(): array
    {
        $headers = [];
        $servers = $this->server;
        $needHeader = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];
        foreach ($servers as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[substr($key, 5)] = $value;
            } elseif (in_array($key, $needHeader, true)) {
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    public function get($param, $default = null)
    {
        return $this->query[$param] ?? $default;
    }

    public function post($param, $default = null)
    {
        return $this->request[$param] ?? $default;
    }

    public function input(string $key, $default = null)
    {
        $input = array_merge_recursive($this->query, $this->request);
        return $input[$key] ?? $default;
    }

    public function common($key, $default = null): string|null
    {
        if (empty($this->commons)) {
            $this->commons = array_merge_recursive($this->query, $this->request);
        }
        return $this->commons[$key] ?? $default;
    }

    public function all(array|string $keys = null, $skipNull = false): array
    {
        $input = array_merge($this->query, $this->request, $this->commons);

        if (!$keys) {
            return $input;
        }
        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            if ($skipNull && !isset($input[$key])) {
                continue;
            }
            $results = array_merge($results, [$key => $input[$key] ?? null]);
        }
        return $results;
    }
}

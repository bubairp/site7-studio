<?php

use craft\helpers\App;

// Detect Docker gateway IP (WSL host IP)
$gatewayIp = 'host.docker.internal';
if (file_exists('/proc/net/route')) {
    $lines = file('/proc/net/route');
    foreach ($lines as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (isset($fields[1]) && $fields[1] === '00000000') {
            $hexIp = $fields[2] ?? '';
            if (strlen($hexIp) === 8) {
                $ipParts = [];
                for ($i = 6; $i >= 0; $i -= 2) {
                    $ipParts[] = hexdec(substr($hexIp, $i, 2));
                }
                $gatewayIp = implode('.', $ipParts);
                break;
            }
        }
    }
}

// Automatically detect where the Vite dev server is running (inside DDEV or WSL host)
$devServerRunning = false;
$devServerHost = '127.0.0.1';

if (App::env('CRAFT_ENVIRONMENT') === 'dev') {
    // 1. Check 127.0.0.1 (Vite running inside DDEV container)
    $fp = @fsockopen('127.0.0.1', 5173, $errno, $errstr, 0.2);
    if ($fp) {
        $devServerRunning = true;
        fclose($fp);
    } else {
        // 2. Check detected gateway IP (Vite running separately on WSL host)
        $fp = @fsockopen($gatewayIp, 5173, $errno, $errstr, 0.2);
        if ($fp) {
            $devServerRunning = true;
            $devServerHost = $gatewayIp;
            fclose($fp);
        }
    }
}

return [
    // Global settings
    '*' => [
        'useDevServer' => App::env('VITE_USE_DEV_SERVER') !== null
            ? filter_var(App::env('VITE_USE_DEV_SERVER'), FILTER_VALIDATE_BOOLEAN)
            : $devServerRunning,
        'manifestPath' => '@webroot/themes/front/.vite/manifest.json',
        'devServerPublic' => App::env('VITE_DEV_SERVER') ?? 'http://localhost:5173/themes/front/',
        'devServerInternal' => App::env('VITE_DEV_SERVER_INTERNAL') ?? "http://{$devServerHost}:5173/themes/front/",
        'serverPublic' => '/themes/front/',
        'errorEntry' => 'src/index.js',
        'useCssPrintMethod' => false,
    ],
    // Live (production) environment
    'live' => [
        'useDevServer' => false,
    ],
    // Staging (pre-production) environment
    'staging' => [
        'useDevServer' => false,
    ],
    // Development environment
    'dev' => [
    ],
];

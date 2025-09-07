<?php
// Router script for PHP development server to handle asset requests properly

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Check if the request is for a static file that exists
if (file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    // Serve static files directly
    return false;
}

// Check if the request is for assets in the themes directory
if (strpos($uri, '/themes/') === 0) {
    $file_path = __DIR__ . $uri;
    if (file_exists($file_path)) {
        // Set appropriate content type
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);
        $mime_types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject'
        ];
        
        if (isset($mime_types[$extension])) {
            header('Content-Type: ' . $mime_types[$extension]);
        }
        
        readfile($file_path);
        return true;
    }
}

// Check if the request is for uploads
if (strpos($uri, '/uploads/') === 0) {
    $file_path = __DIR__ . $uri;
    if (file_exists($file_path)) {
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);
        $mime_types = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon'
        ];
        
        if (isset($mime_types[$extension])) {
            header('Content-Type: ' . $mime_types[$extension]);
        }
        
        readfile($file_path);
        return true;
    }
}

// Check if the request is for install directory
if (strpos($uri, '/install/') === 0) {
    $file_path = __DIR__ . $uri;
    if (file_exists($file_path)) {
        return false; // Let PHP serve it directly
    }
}

// Check for Paddle checkout URLs with _ptxn parameter
if (isset($_GET['_ptxn']) && !empty($_GET['_ptxn'])) {
    // This is a Paddle checkout URL, preserve the _ptxn parameter
    $path = ltrim($uri, '/');
    if (!empty($path)) {
        $_GET['altum'] = $path;
    }
    // Keep the _ptxn parameter in $_GET
} else {
    // For all other requests, simulate the .htaccess rewrite rule
    // RewriteRule ^(.+)$ index.php?altum=$1 [QSA,L]
    $path = ltrim($uri, '/');
    if (!empty($path)) {
        $_GET['altum'] = $path;
    }
}

// Serve the main index.php
require_once __DIR__ . '/index.php';

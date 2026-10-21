<?php
header('Content-Type: text/plain');

echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'unset') . "\n";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'unset') . "\n";
echo "PHP_SELF: " . ($_SERVER['PHP_SELF'] ?? 'unset') . "\n";
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'unset') . "\n";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'unset') . "\n";

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$request = \Illuminate\Http\Request::capture();
echo "\n--- Laravel Request Analysis ---\n";
echo "Laravel Path: " . $request->path() . "\n";
echo "Laravel URL: " . $request->url() . "\n";
echo "Laravel Full URL: " . $request->fullUrl() . "\n";
echo "Laravel Root: " . $request->root() . "\n";
echo "Laravel Base URL: " . $request->getBaseUrl() . "\n";
echo "Laravel Base Path: " . $request->getBasePath() . "\n";

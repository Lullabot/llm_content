<?php

/**
 * @file
 * Bootstrap for llm_content unit tests.
 */

declare(strict_types=1);

// Find the Composer autoloader. When running standalone (CI) the vendor
// directory is at the module root; inside a Drupal site it is five levels up.
$moduleRoot = dirname(__DIR__);
$candidates = [
  $moduleRoot . '/vendor/autoload.php',
  dirname($moduleRoot, 4) . '/vendor/autoload.php',
];
$autoloader = NULL;
foreach ($candidates as $candidate) {
  if (file_exists($candidate)) {
    $autoloader = require $candidate;
    break;
  }
}
if (!$autoloader) {
  throw new \RuntimeException('Could not locate Composer autoloader.');
}

// Register the module's PSR-4 namespace.
$autoloader->addPsr4('Drupal\\llm_content\\', $moduleRoot . '/src');
$autoloader->addPsr4('Drupal\\Tests\\llm_content\\', $moduleRoot . '/tests/src');

// Define requirement severity constants for D10 compatibility.
if (!defined('REQUIREMENT_ERROR')) {
  define('REQUIREMENT_ERROR', 2);
  define('REQUIREMENT_OK', 0);
}

// Register core module namespaces needed by unit tests.
// In a full Drupal site these live under web/core/modules; in CI they are
// under vendor/drupal/core/modules.
$corePaths = [
  dirname($moduleRoot, 4) . '/core/modules',
  $moduleRoot . '/vendor/drupal/core/modules',
];
foreach ($corePaths as $corePath) {
  if (is_dir($corePath)) {
    $autoloader->addPsr4('Drupal\\path_alias\\', $corePath . '/path_alias/src');
    $autoloader->addPsr4('Drupal\\node\\', $corePath . '/node/src');
    $autoloader->addPsr4('Drupal\\user\\', $corePath . '/user/src');
    break;
  }
}

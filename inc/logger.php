<?php
if (!defined('GLPI_ROOT')) {
   // Allow inclusion even when GLPI_ROOT isn't defined in tests
   define('GLPI_ROOT', '/var/www/glpi');
}

if (!function_exists('nextool_log')) {
   function nextool_log(string $file, string $message): void {
      if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
         Toolbox::logInFile($file, $message);
      } else {
         error_log('[' . $file . '] ' . $message);
      }
   }
}

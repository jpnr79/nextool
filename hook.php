<?php
/*if (!defined('GLPI_ROOT')) { define('GLPI_ROOT', realpath(__DIR__ . '/../..')); }
/**
 * Hooks do plugin Nextool v2.x
 *
 * Fluxo de instalação:
 * 1. Executa sql/install.sql (cria tabelas básicas e seeds)
 * 2. Gera automaticamente o client_identifier
 *
 * Fluxo de desinstalação:
 * 1. Executa sql/uninstall.sql (remove apenas tabelas operacionais)
 * 2. Remove diretórios físicos dos módulos baixados
 *
 * @version 2.x-dev
 * @author Richard Loureiro - linkedin.com/in/richard-ti
 * @license GPLv3+
 * @link https://linkedin.com/in/richard-ti
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

if (file_exists(GLPI_ROOT . '/plugins/nextool/inc/logger.php')) {
   require_once GLPI_ROOT . '/plugins/nextool/inc/logger.php';
}

require_once __DIR__ . '/inc/modulemanager.class.php';
require_once __DIR__ . '/inc/basemodule.class.php';
require_once __DIR__ . '/inc/permissionmanager.class.php';

function plugin_nextool_install() {
   global $DB;

   $sqlfile = GLPI_ROOT . '/plugins/nextool/sql/install.sql';
   if (file_exists($sqlfile)) {
      $DB->runFile($sqlfile);
   }

   $configfile = GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
   if (file_exists($configfile)) {
      require_once $configfile;
      if (class_exists('PluginNextoolConfig')) {
         try {
            PluginNextoolConfig::getConfig();
         } catch (Exception $e) {
            $__nextool_msg = "Erro ao inicializar client_identifier durante install: " . $e->getMessage();
            if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
               Toolbox::logInFile('plugin_nextool', $__nextool_msg);
            } else {
               error_log('[plugin_nextool] ' . $__nextool_msg);
            }
         }
      }
   }

      try {
         $manager = PluginNextoolModuleManager::getInstance();
         $manager->refreshModules();
         $__nextool_msg = sprintf('Install health-check: %d módulos detectados após reinstalação.', count($manager->getAllModules()));
         if (function_exists('nextool_log')) {
            nextool_log('plugin_nextool', $__nextool_msg);
         } else {
            error_log('[plugin_nextool] ' . $__nextool_msg);
         }
      } catch (Throwable $e) {
         $__nextool_msg = 'Install health-check falhou: ' . $e->getMessage();
         if (function_exists('nextool_log')) {
            nextool_log('plugin_nextool', $__nextool_msg);
         } else {
            error_log('[plugin_nextool] ' . $__nextool_msg);
         }
      }

   PluginNextoolPermissionManager::installRights();
   PluginNextoolPermissionManager::syncModuleRights();

   return true;
}

function plugin_nextool_upgrade($old_version) {
   $result = plugin_nextool_install();
   PluginNextoolPermissionManager::syncModuleRights();
   return $result;
}

/**
 * Hook de desinstalação
 * 
 * Remove estrutura de banco de dados e desinstala módulos usando o SQL dedicado.
 */
function plugin_nextool_uninstall() {
   global $DB;

   $manager = PluginNextoolModuleManager::getInstance();
   $modulesTable = 'glpi_plugin_nextool_main_modules';
   if ($DB->tableExists($modulesTable)) {
      $iterator = $DB->request([
         'FROM'  => $modulesTable,
         'WHERE' => ['is_installed' => 1]
      ]);
      foreach ($iterator as $row) {
         $moduleKey = $row['module_key'] ?? '';
         if ($moduleKey !== '') {
            try {
               $manager->uninstallModule($moduleKey);
            } catch (Throwable $e) {
                  $__nextool_msg = sprintf('Falha ao desinstalar módulo %s durante plugin_uninstall: %s', $moduleKey, $e->getMessage());
                        if (function_exists('nextool_log')) {
                           nextool_log('plugin_nextool', $__nextool_msg);
                        } else {
                           error_log('[plugin_nextool] ' . $__nextool_msg);
                        }
            }
         }
      }
   }

   $sqlfile = GLPI_ROOT . '/plugins/nextool/sql/uninstall.sql';
   if (file_exists($sqlfile)) {
      $DB->runFile($sqlfile);
   }

   // Remove diretórios de módulos baixados (dados continuarão no banco)
   $modulesDir = GLPI_ROOT . '/plugins/nextool/modules';
   if (is_dir($modulesDir)) {
      foreach (glob($modulesDir . '/*') as $entry) {
         if (is_dir($entry)) {
            nextool_delete_dir($entry);
         }
      }
   }

   // Remove cache de descoberta de módulos e diretório temporário de downloads
   $manager->clearCache();

   $tmpRemoteDir = GLPI_TMP_DIR . '/nextool_remote';
   if (is_dir($tmpRemoteDir)) {
      nextool_delete_dir($tmpRemoteDir);
   }

   $__nextool_msg = 'Plugin desinstalado: módulos removidos, caches limpos e diretórios temporários apagados.';
   if (function_exists('nextool_log')) {
      nextool_log('plugin_nextool', $__nextool_msg);
   } else {
      error_log('[plugin_nextool] ' . $__nextool_msg);
   }

   PluginNextoolPermissionManager::removeRights();

   return true;
}

function nextool_delete_dir(string $dir): void {
   if (!is_dir($dir)) {
      return;
   }

   $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
   );

   foreach ($iterator as $item) {
      $path = $item->getPathname();
      if ($item->isDir()) {
         @rmdir($path);
      } else {
         @unlink($path);
      }
   }

   @rmdir($dir);
}
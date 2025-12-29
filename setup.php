<?php
/*if (!defined('GLPI_ROOT')) { define('GLPI_ROOT', realpath(__DIR__ . '/../..')); }
/**
 * Plugin NexTool Solutions v2.19.0 - Sistema Modular para GLPI 11
 * 
 * Plugin multiferramentas com arquitetura modular completa.
 * Cada ferramenta é um módulo independente que pode ser ativado/desativado.
 * 
 * Arquitetura:
 * - ModuleManager: Gerenciador central (auto-discovery de módulos)
 * - BaseModule: Classe abstrata base para todos os módulos
 * - Módulos: modules/[nome]/[nome].class.php
 * 
 * Documentação completa em: docs/
 * 
 * @version 2.19.0
 * @author Richard Loureiro - linkedin.com/in/richard-ti
 * @license GPLv3+
 * @link https://linkedin.com/in/richard-ti
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

// Shared logger helper
if (file_exists(GLPI_ROOT . '/plugins/nextool/inc/logger.php')) {
   require_once GLPI_ROOT . '/plugins/nextool/inc/logger.php';
}

function plugin_version_nextool() {
   return [
      'name'           => 'NexTool Solutions',
      'version'        => '2.19.0',
      'license'        => 'GPLv3+',
      'author'         => 'Richard Loureiro - linkedin.com/in/richard-ti',
      'homepage'       => 'https://ritech.site',
      'requirements'   => ['glpi' => ['min' => '11.0', 'max' => '12.0']],
   ];
}

/**
 * Inicialização do plugin
 */
function plugin_init_nextool() {
   global $PLUGIN_HOOKS;

   $permissionfile = GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
   if (file_exists($permissionfile)) {
      require_once $permissionfile;
   }

   // Define a página de configuração do plugin (adiciona botão "Configurar" na lista de plugins)
   $PLUGIN_HOOKS['config_page']['nextool'] = 'front/config.php';

   // Gera e persiste o Identificador do Cliente no momento em que o plugin é carregado (ativado)
   // em vez de depender apenas da primeira leitura preguiçosa da configuração.
   // Isso garante que, após a ativação, o ambiente já tenha um client_identifier estável.
   $configfile = GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
   if (file_exists($configfile)) {
      require_once $configfile;
      if (class_exists('PluginNextoolConfig')) {
         try {
            PluginNextoolConfig::getConfig();
         } catch (Exception $e) {
            $__nextool_msg = "Erro ao inicializar client_identifier: " . $e->getMessage();
            if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
               Toolbox::logInFile('plugin_nextool', $__nextool_msg);
            } else {
               error_log('[plugin_nextool] ' . $__nextool_msg);
            }
         }
      }
   }

   // Carrega classe de setup
   $setupfile = GLPI_ROOT . '/plugins/nextool/inc/setup.class.php';
   if (file_exists($setupfile)) {
      require_once $setupfile;
      
      // Registra classe para adicionar tab em "Configurar → Geral"
      Plugin::registerClass('PluginNextoolSetup', [
         'addtabon' => ['Config']
      ]);
   }

   $profilefile = GLPI_ROOT . '/plugins/nextool/inc/profile.class.php';
   if (file_exists($profilefile)) {
      require_once $profilefile;
      Plugin::registerClass('PluginNextoolProfile', ['addtabon' => ['Profile']]);
   }

   // Carrega ModuleManager e inicializa módulos ativos
   // Verifica se tabela de módulos existe (plugin já instalado)
   $managerfile = GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
   $basefile = GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';
   
   if (file_exists($managerfile) && file_exists($basefile)) {
      global $DB;
      
      // Só carrega módulos se plugin já foi instalado
      if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
         try {
            require_once $basefile;
            require_once $managerfile;
            
            $manager = PluginNextoolModuleManager::getInstance();
            $manager->loadActiveModules();
            PluginNextoolPermissionManager::syncModuleRights();
            
         } catch (Exception $e) {
            $__nextool_msg = "Erro ao carregar módulos: " . $e->getMessage();
            if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
               Toolbox::logInFile('plugin_nextool', $__nextool_msg);
            } else {
               error_log('[plugin_nextool] ' . $__nextool_msg);
            }
         }
      }
   }
}

/**
 * Verifica pré-requisitos
 */
function plugin_nextool_check_prerequisites() {
   $min_version = '11.0';
   $max_version = '12.0';
   $glpi_version = null;
   $glpi_root = defined('GLPI_ROOT') ? GLPI_ROOT : '/var/www/glpi';

   // Try GLPI 11+ version directory
   $version_dir = rtrim($glpi_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'version';
   if (is_dir($version_dir)) {
      $files = scandir($version_dir, SCANDIR_SORT_DESCENDING);
      foreach ($files as $file) {
         if ($file[0] !== '.' && preg_match('/^\d+\.\d+(?:\.\d+)?$/', $file)) {
            $glpi_version = $file;
            break;
         }
      }
   }

   // Fallback for older GLPI installations
   if ($glpi_version === null && defined('GLPI_VERSION')) {
      $glpi_version = GLPI_VERSION;
   }

   // Try to load Toolbox for logging if not already loaded
   if (!class_exists('Toolbox') && file_exists(rtrim($glpi_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Toolbox.php')) {
      require_once rtrim($glpi_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Toolbox.php';
   }

   if ($glpi_version === null) {
      if (function_exists('nextool_log')) {
         nextool_log('plugin_nextool', '[setup.php:plugin_nextool_check_prerequisites] ERROR: GLPI version not detected.');
      } else {
         error_log('[plugin_nextool] [setup.php:plugin_nextool_check_prerequisites] ERROR: GLPI version not detected.');
      }
      echo "Não foi possível detectar a versão do GLPI. Verifique os logs.";
      return false;
   }

   if (version_compare($glpi_version, $min_version, '<')) {
      if (function_exists('nextool_log')) {
         nextool_log('plugin_nextool', sprintf(
            'ERROR [setup.php:plugin_nextool_check_prerequisites] GLPI version %s is less than required minimum %s, user=%s',
            $glpi_version, $min_version, $_SESSION['glpiname'] ?? 'unknown'
         ));
      } else {
         error_log(sprintf('[plugin_nextool] ERROR [setup.php:plugin_nextool_check_prerequisites] GLPI version %s is less than required minimum %s, user=%s', $glpi_version, $min_version, $_SESSION['glpiname'] ?? 'unknown'));
      }
      echo "Este plugin requer GLPI >= {$min_version}";
      return false;
   }

   if (version_compare($glpi_version, $max_version, '>')) {
      if (function_exists('nextool_log')) {
         nextool_log('plugin_nextool', sprintf(
            'ERROR [setup.php:plugin_nextool_check_prerequisites] GLPI version %s is greater than supported maximum %s, user=%s',
            $glpi_version, $max_version, $_SESSION['glpiname'] ?? 'unknown'
         ));
      } else {
         error_log(sprintf('[plugin_nextool] ERROR [setup.php:plugin_nextool_check_prerequisites] GLPI version %s is greater than supported maximum %s, user=%s', $glpi_version, $max_version, $_SESSION['glpiname'] ?? 'unknown'));
      }
      echo "Este plugin é suportado até GLPI < {$max_version}";
      return false;
   }

   return true;
}

/**
 * Verifica configuração
 */
function plugin_nextool_check_config() {
   return true;
}


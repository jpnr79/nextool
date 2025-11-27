<?php
/**
 * Hooks do plugin Nextool v2.0.0
 * 
 * Gerencia instalação/desinstalação do plugin e seus módulos.
 * 
 * Fluxo de Instalação:
 * 1. Cria tabelas principais (configs, modules)
 * 2. ModuleManager descobre módulos disponíveis
 * 3. Chama install() de cada módulo descoberto
 * 
 * Fluxo de Desinstalação:
 * 1. ModuleManager chama uninstall() de cada módulo
 * 2. Remove tabelas principais
 * 
 * @version 2.0.0
 * @author Richard Loureiro
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/inc/modulecatalog.class.php';

/**
 * Hook de instalação
 * 
 * Cria estrutura de banco de dados e instala módulos disponíveis.
 * O ModuleManager é usado para auto-discovery e instalação automática dos módulos.
 */
function plugin_nextool_install() {
   global $DB;
   
   $migration = new Migration(100);
   
   // ====================================
   // 1. TABELAS PRINCIPAIS
   // ====================================
   
   // Cria tabela de configuração global usando Migration (API correta do GLPI 11)
   if (!$DB->tableExists('glpi_plugin_nextool_main_configs')) {
      $query = "CREATE TABLE `glpi_plugin_nextool_main_configs` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `is_active` tinyint NOT NULL DEFAULT '0' COMMENT 'Plugin ativo (0=não, 1=sim)',
         `client_identifier` varchar(128) DEFAULT NULL,
         `endpoint_url` varchar(255) DEFAULT NULL,
         `date_creation` timestamp NULL DEFAULT NULL,
         `date_mod` timestamp NULL DEFAULT NULL,
         PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";
      
      $migration->addPreQuery($query);
   }

   // Auditoria de ações de módulos
   if (!$DB->tableExists('glpi_plugin_nextool_main_module_audit')) {
      $query = "CREATE TABLE `glpi_plugin_nextool_main_module_audit` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `action_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `module_key` varchar(100) NOT NULL,
         `action` varchar(32) NOT NULL COMMENT 'install/enable/disable/uninstall',
         `result` tinyint NOT NULL DEFAULT '0' COMMENT '0=falha, 1=sucesso',
         `message` text DEFAULT NULL COMMENT 'Mensagem resumindo o resultado',
         `user_id` int unsigned DEFAULT NULL COMMENT 'Usuário autenticado (se houver)',
         `origin` varchar(64) DEFAULT NULL COMMENT 'Origem declarada pela ação',
         `source_ip` varchar(45) DEFAULT NULL COMMENT 'IP do solicitante',
         `license_status` varchar(32) DEFAULT NULL,
         `contract_active` tinyint DEFAULT NULL,
         `plan` varchar(32) DEFAULT NULL,
         `allowed_modules` text DEFAULT NULL COMMENT 'Snapshot de allowed_modules (JSON)',
         `requested_modules` text DEFAULT NULL COMMENT 'Snapshot dos módulos solicitados (JSON)',
         PRIMARY KEY (`id`),
         KEY `module_key` (`module_key`),
         KEY `action_date` (`action_date`),
         KEY `action` (`action`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

      $migration->addPreQuery($query);
   }

   if (!$DB->tableExists('glpi_plugin_nextool_main_config_audit')) {
      $query = "CREATE TABLE `glpi_plugin_nextool_main_config_audit` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `event_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `section` varchar(32) NOT NULL COMMENT 'global|license|validation',
         `action` varchar(64) DEFAULT NULL,
         `result` tinyint DEFAULT NULL COMMENT '0=falha,1=sucesso',
         `message` text DEFAULT NULL,
         `user_id` int unsigned DEFAULT NULL,
         `source_ip` varchar(45) DEFAULT NULL,
         `details` text DEFAULT NULL COMMENT 'JSON com campos/valores',
         PRIMARY KEY (`id`),
         KEY `section` (`section`),
         KEY `event_date` (`event_date`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

      $migration->addPreQuery($query);
   }
   
   // Garante que campos novos existam em instalações antigas
   if ($DB->tableExists('glpi_plugin_nextool_main_configs')) {
      if (!$DB->fieldExists('glpi_plugin_nextool_main_configs', 'client_identifier')) {
         $migration->addPreQuery("ALTER TABLE `glpi_plugin_nextool_main_configs` ADD COLUMN `client_identifier` varchar(128) DEFAULT NULL AFTER `is_active`");
      }
      if (!$DB->fieldExists('glpi_plugin_nextool_main_configs', 'endpoint_url')) {
         $migration->addPreQuery("ALTER TABLE `glpi_plugin_nextool_main_configs` ADD COLUMN `endpoint_url` varchar(255) DEFAULT NULL AFTER `client_identifier`");
      }
   }

   // Cria tabela de módulos
   if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
      $query = "CREATE TABLE `glpi_plugin_nextool_main_modules` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `module_key` varchar(100) NOT NULL COMMENT 'Chave única do módulo',
         `name` varchar(255) NOT NULL COMMENT 'Nome amigável do módulo',
         `version` varchar(20) DEFAULT NULL COMMENT 'Versão instalada do módulo',
         `available_version` varchar(20) DEFAULT NULL COMMENT 'Última versão disponível no catálogo oficial',
         `is_installed` tinyint NOT NULL DEFAULT '0' COMMENT 'Módulo instalado (0=não, 1=sim)',
         `billing_tier` varchar(16) DEFAULT 'FREE' COMMENT 'Modelo de cobrança (FREE/PAID/...)',
         `is_enabled` tinyint NOT NULL DEFAULT '0' COMMENT 'Módulo ativo (0=não, 1=sim)',
         `is_available` tinyint NOT NULL DEFAULT '1' COMMENT 'Disponível na lista de módulos (0=oculto, 1=visível)',
         `config` text DEFAULT NULL COMMENT 'Configuração do módulo (JSON)',
         `date_creation` timestamp NULL DEFAULT NULL,
         `date_mod` timestamp NULL DEFAULT NULL,
         PRIMARY KEY (`id`),
         UNIQUE KEY `module_key` (`module_key`),
         KEY `is_enabled` (`is_enabled`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";
      
      $migration->addPreQuery($query);
   } else {
      // Garante que campos novos existam em instalações antigas
      if (!$DB->fieldExists('glpi_plugin_nextool_main_modules', 'is_installed')) {
         $migration->addPreQuery(
            "ALTER TABLE `glpi_plugin_nextool_main_modules`
             ADD COLUMN `is_installed` tinyint NOT NULL DEFAULT '0'
             COMMENT 'Módulo instalado (0=não, 1=sim)' AFTER `version`"
         );
      }
      if (!$DB->fieldExists('glpi_plugin_nextool_main_modules', 'billing_tier')) {
         $migration->addPreQuery(
            "ALTER TABLE `glpi_plugin_nextool_main_modules`
             ADD COLUMN `billing_tier` varchar(16) DEFAULT 'FREE'
             COMMENT 'Modelo de cobrança (FREE/PAID/...)' AFTER `is_installed`"
         );
      }
      if (!$DB->fieldExists('glpi_plugin_nextool_main_modules', 'is_available')) {
         $migration->addPreQuery(
            "ALTER TABLE `glpi_plugin_nextool_main_modules`
             ADD COLUMN `is_available` tinyint NOT NULL DEFAULT '1'
             COMMENT 'Disponível na lista de módulos (0=oculto, 1=visível)' AFTER `is_enabled`"
         );
      }
      if ($DB->fieldExists('glpi_plugin_nextool_main_modules', 'version')) {
         $migration->addPreQuery(
            "ALTER TABLE `glpi_plugin_nextool_main_modules`
             MODIFY `version` varchar(20) DEFAULT NULL COMMENT 'Versão instalada do módulo'"
         );
      }
      if (!$DB->fieldExists('glpi_plugin_nextool_main_modules', 'available_version')) {
         $migration->addPreQuery(
            "ALTER TABLE `glpi_plugin_nextool_main_modules`
             ADD COLUMN `available_version` varchar(20) DEFAULT NULL
             COMMENT 'Última versão disponível no catálogo oficial' AFTER `version`"
         );
      }
   }

   // Tabelas de licenciamento no operacional (LicenseConfig + ValidationAttempts)
   if (!$DB->tableExists('glpi_plugin_nextool_main_license_config')) {
      $query = "CREATE TABLE `glpi_plugin_nextool_main_license_config` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `license_key` varchar(255) DEFAULT NULL COMMENT 'Chave da licença configurada',
         `plan` varchar(32) DEFAULT NULL COMMENT 'Plano da licença (UNKNOWN/FREE/STARTER/PRO/ENTERPRISE)',
         `api_endpoint` varchar(500) DEFAULT NULL COMMENT 'URL do endpoint de validação',
         `api_secret` varchar(255) DEFAULT NULL COMMENT 'Secret para autenticação (opcional)',
         `last_validation_date` timestamp NULL DEFAULT NULL COMMENT 'Data da última validação bem-sucedida',
         `last_validation_result` tinyint DEFAULT NULL COMMENT 'Resultado da última validação (0=inválida, 1=válida)',
         `last_validation_message` text DEFAULT NULL COMMENT 'Mensagem da última validação',
         `cached_modules` text DEFAULT NULL COMMENT 'Módulos permitidos em cache (JSON)',
         `consecutive_failures` int unsigned NOT NULL DEFAULT '0' COMMENT 'Falhas consecutivas de comunicação',
         `last_failure_date` timestamp NULL DEFAULT NULL COMMENT 'Data da última falha',
         `date_creation` timestamp NULL DEFAULT NULL,
         `date_mod` timestamp NULL DEFAULT NULL,
         PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

      $migration->addPreQuery($query);
   } else {
      // Garante que o campo plan exista em instalações antigas
      if (!$DB->fieldExists('glpi_plugin_nextool_main_license_config', 'contract_active')) {
         $migration->addPreQuery("ALTER TABLE `glpi_plugin_nextool_main_license_config`
          ADD COLUMN `contract_active` tinyint DEFAULT NULL COMMENT 'Último estado do contrato retornado pelo administrativo' AFTER `plan`");
      }
      if (!$DB->fieldExists('glpi_plugin_nextool_main_license_config', 'license_status')) {
         $migration->addPreQuery("ALTER TABLE `glpi_plugin_nextool_main_license_config`
          ADD COLUMN `license_status` varchar(32) DEFAULT NULL COMMENT 'Último status retornado pelo administrativo' AFTER `contract_active`");
      }
      if (!$DB->fieldExists('glpi_plugin_nextool_main_license_config', 'expires_at')) {
         $migration->addPreQuery("ALTER TABLE `glpi_plugin_nextool_main_license_config`
          ADD COLUMN `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Data de expiração retornada pelo administrativo' AFTER `license_status`");
      }
      if (!$DB->fieldExists('glpi_plugin_nextool_main_license_config', 'warnings')) {
         $migration->addPreQuery("ALTER TABLE `glpi_plugin_nextool_main_license_config`
          ADD COLUMN `warnings` text DEFAULT NULL COMMENT 'Warnings retornados pelo administrativo (JSON)' AFTER `cached_modules`");
      }
      if (!$DB->fieldExists('glpi_plugin_nextool_main_license_config', 'licenses_snapshot')) {
         $migration->addPreQuery("ALTER TABLE `glpi_plugin_nextool_main_license_config`
          ADD COLUMN `licenses_snapshot` longtext DEFAULT NULL COMMENT 'Snapshot consolidado das licenças (JSON)' AFTER `warnings`");
      }
      if (!$DB->fieldExists('glpi_plugin_nextool_main_license_config', 'plan')) {
         $migration->addPreQuery("ALTER TABLE `glpi_plugin_nextool_main_license_config` ADD COLUMN `plan` varchar(32) DEFAULT NULL COMMENT 'Plano da licença (UNKNOWN/FREE/STARTER/PRO/ENTERPRISE)' AFTER `license_key`");
      }
   }

   if (!$DB->tableExists('glpi_plugin_nextool_main_validation_attempts')) {
      $query = "CREATE TABLE `glpi_plugin_nextool_main_validation_attempts` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `attempt_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `result` tinyint NOT NULL COMMENT '0=falha, 1=sucesso',
         `message` text DEFAULT NULL COMMENT 'Mensagem de erro ou sucesso',
         `http_code` int DEFAULT NULL COMMENT 'Código HTTP da resposta',
         `response_time_ms` int DEFAULT NULL COMMENT 'Tempo de resposta em milissegundos',
         `origin` varchar(64) DEFAULT NULL COMMENT 'Contexto informado pelo chamador',
         `requested_modules` text DEFAULT NULL COMMENT 'Lista de módulos solicitados (JSON)',
         `client_identifier` varchar(191) DEFAULT NULL COMMENT 'Identificador do ambiente local',
         `license_status` varchar(32) DEFAULT NULL COMMENT 'Status retornado pelo administrativo',
         `contract_active` tinyint DEFAULT NULL COMMENT 'Contrato ativo retornado pelo administrativo',
         `plan` varchar(32) DEFAULT NULL COMMENT 'Plano retornado pelo administrativo',
         `force_refresh` tinyint DEFAULT NULL COMMENT '1 quando cache foi ignorado',
         `cache_hit` tinyint DEFAULT NULL COMMENT '1 quando resposta veio do cache local',
         `user_id` int unsigned DEFAULT NULL COMMENT 'Usuário autenticado que originou a chamada',
         PRIMARY KEY (`id`),
         KEY `attempt_date` (`attempt_date`),
         KEY `result` (`result`),
         KEY `origin` (`origin`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

      $migration->addPreQuery($query);
   } else {
      $table = 'glpi_plugin_nextool_main_validation_attempts';
      $fields = [
         ['origin', "ALTER TABLE `$table` ADD COLUMN `origin` varchar(64) DEFAULT NULL COMMENT 'Contexto informado pelo chamador' AFTER `response_time_ms`"],
         ['requested_modules', "ALTER TABLE `$table` ADD COLUMN `requested_modules` text DEFAULT NULL COMMENT 'Lista de módulos solicitados (JSON)' AFTER `origin`"],
         ['client_identifier', "ALTER TABLE `$table` ADD COLUMN `client_identifier` varchar(191) DEFAULT NULL COMMENT 'Identificador do ambiente local' AFTER `requested_modules`"],
         ['license_status', "ALTER TABLE `$table` ADD COLUMN `license_status` varchar(32) DEFAULT NULL COMMENT 'Status retornado pelo administrativo' AFTER `client_identifier`"],
         ['contract_active', "ALTER TABLE `$table` ADD COLUMN `contract_active` tinyint DEFAULT NULL COMMENT 'Contrato ativo retornado pelo administrativo' AFTER `license_status`"],
         ['plan', "ALTER TABLE `$table` ADD COLUMN `plan` varchar(32) DEFAULT NULL COMMENT 'Plano retornado pelo administrativo' AFTER `contract_active`"],
         ['force_refresh', "ALTER TABLE `$table` ADD COLUMN `force_refresh` tinyint DEFAULT NULL COMMENT '1 quando cache foi ignorado' AFTER `plan`"],
         ['cache_hit', "ALTER TABLE `$table` ADD COLUMN `cache_hit` tinyint DEFAULT NULL COMMENT '1 quando resposta veio do cache local' AFTER `force_refresh`"],
         ['user_id', "ALTER TABLE `$table` ADD COLUMN `user_id` int unsigned DEFAULT NULL COMMENT 'Usuário autenticado que originou a chamada' AFTER `cache_hit`"],
      ];

      foreach ($fields as [$field, $sql]) {
         if (!$DB->fieldExists($table, $field)) {
            $migration->addPreQuery($sql);
         }
      }

      $migration->addKey($table, ['origin'], 'origin');
   }

   $migration->executeMigration();

   // ====================================
   // 2. DADOS INICIAIS E SYNC LEGADA
   // ====================================

   if ($DB->tableExists('glpi_plugin_nextool_main_configs')) {
      if (countElementsInTable('glpi_plugin_nextool_main_configs', ['id' => 1]) === 0) {
         $DB->insert(
            'glpi_plugin_nextool_main_configs',
            [
               'id' => 1,
               'is_active' => 0,
               'date_creation' => date('Y-m-d H:i:s')
            ]
         );
      }
   }

   // Gera e persiste o client_identifier imediatamente após instalação bem-sucedida
   $configfile = GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
   if (file_exists($configfile)) {
      require_once $configfile;
      if (class_exists('PluginNextoolConfig')) {
         try {
            PluginNextoolConfig::getConfig();
         } catch (Exception $e) {
            Toolbox::logInFile('plugin_nextool', "Erro ao inicializar client_identifier durante install: " . $e->getMessage());
         }
      }
   }

   // ====================================
   // 3. REGISTRO INICIAL DE MÓDULOS
   // ====================================
   //
   // Objetivo: garantir que a tabela glpi_plugin_nextool_main_modules já contenha
   // um registro básico para cada módulo disponível logo após a instalação do
   // plugin, sem instalar efetivamente os módulos (install()).
   //
   if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
      foreach (PluginNextoolModuleCatalog::all() as $moduleKey => $meta) {
         if (countElementsInTable('glpi_plugin_nextool_main_modules', ['module_key' => $moduleKey]) === 0) {
            $DB->insert(
               'glpi_plugin_nextool_main_modules',
               [
                  'module_key'    => $moduleKey,
                  'name'          => $meta['name'],
                  'version'       => $meta['version'],
                  'billing_tier'  => strtoupper($meta['billing_tier']),
                  'is_enabled'    => 0,
                  'is_installed'  => 0,
                  'is_available'  => 1,
                  'config'        => json_encode(new stdClass()),
                  'date_creation' => date('Y-m-d H:i:s'),
               ]
            );
         }
      }
   }

   return true;
}

/**
 * Hook de upgrade
 */
function plugin_nextool_upgrade($old_version) {
   // Executa SQL de upgrade para adicionar novos campos globais
   $sqlfile = GLPI_ROOT . '/plugins/nextool/sql/upgrade_20600.sql';
   $migration = new Migration(100);
   if (file_exists($sqlfile)) {
      // Tenta executar; se o banco não suportar IF NOT EXISTS, erros serão ignorados pelo GLPI/DB layer?
      // Usamos doQuery direto pois é um arquivo simples com ALTER TABLE.
      $sql = file_get_contents($sqlfile);
      if ($sql !== false) {
         foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
               $migration->addSql($stmt);
            }
         }
      }
   }
   $migration->executeMigration();
   return plugin_nextool_install();
}

/**
 * Hook de desinstalação
 * 
 * Remove estrutura de banco de dados e desinstala módulos.
 * O ModuleManager é usado para desinstalação automática dos módulos.
 */
function plugin_nextool_uninstall() {
   global $DB;

   // Remove diretórios de módulos baixados (dados continuarão no banco)
   $modulesDir = GLPI_ROOT . '/plugins/nextool/modules';
   if (is_dir($modulesDir)) {
      foreach (glob($modulesDir . '/*') as $entry) {
         if (is_dir($entry)) {
            nextool_delete_dir($entry);
         }
      }
   }
   
   // ====================================
   // 2. REMOVER TABELAS PRINCIPAIS
   // ====================================
   
   // Remove tabela de módulos
   $migration = new Migration(100);
   if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
      $migration->dropTable('glpi_plugin_nextool_main_modules');
   }

   if ($DB->tableExists('glpi_plugin_nextool_main_configs')) {
      $migration->dropTable('glpi_plugin_nextool_main_configs');
   }

   if ($DB->tableExists('glpi_plugin_nextool_main_license_config')) {
      $migration->dropTable('glpi_plugin_nextool_main_license_config');
   }

   if ($DB->tableExists('glpi_plugin_nextool_main_validation_attempts')) {
      $migration->dropTable('glpi_plugin_nextool_main_validation_attempts');
   }

   if ($DB->tableExists('glpi_plugin_nextool_main_module_audit')) {
      $migration->dropTable('glpi_plugin_nextool_main_module_audit');
   }

   if ($DB->tableExists('glpi_plugin_nextool_main_config_audit')) {
      $migration->dropTable('glpi_plugin_nextool_main_config_audit');
   }

   $migration->executeMigration();

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


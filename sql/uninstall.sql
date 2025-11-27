-- Desinstalação do plugin Nextool (remove estrutura principal e licenciamento)
--
-- Este arquivo é executado na desinstalação do plugin.
-- A partir da v2.0.0, o plugin usa arquitetura modular.

-- NOTA: As tabelas específicas dos módulos são removidas pelo método uninstall() de cada módulo
-- O ModuleManager chama automaticamente uninstall() de cada módulo antes de remover as tabelas principais

-- Remove tabelas de licenciamento do operacional
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_validation_attempts`;
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_license_config`;
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_module_audit`;
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_config_audit`;

-- Remove tabela de módulos
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_modules`;

-- Remove tabela de configuração global
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_configs`;


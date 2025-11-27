<?php
/**
 * Redireciona para a página de configuração do plugin
 * 
 * Este arquivo é chamado quando o usuário clica no botão "Configurar"
 * na página de plugins (Configurar → Plugins)
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

// Redireciona para Configurar → Geral com a tab do plugin forçada
$target = Toolbox::getItemTypeFormURL('Config') . '?forcetab=PluginNextoolSetup$1';

Html::redirect($target);


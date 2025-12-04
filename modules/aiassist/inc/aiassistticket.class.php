<?php
/**
 * Classe responsável por registrar a aba "AI Assist" nos chamados.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAiassistTicket extends CommonDBTM {

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      // Verifica permissão de visualização do módulo
      if (!PluginNextoolPermissionManager::canViewModule('aiassist')) {
         return '';
      }

      if ($item instanceof Ticket) {
         return "<span class='d-inline-flex align-items-center gap-1'><i class='ti ti-robot'></i><span>AI Assist</span></span>";
      }
      return '';
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      // Verifica permissão de visualização do módulo
      if (!PluginNextoolPermissionManager::canViewModule('aiassist')) {
         echo '<div class="alert alert-warning">' . __('Você não tem permissão para visualizar este módulo.', 'nextool') . '</div>';
         return false;
      }

      if (!($item instanceof Ticket)) {
         return false;
      }

      $module = new PluginNextoolAiassist();
      $ticket = $item;

      include GLPI_ROOT . '/plugins/nextool/modules/aiassist/front/aiassist.ticket.php';
      return true;
   }
}


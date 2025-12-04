<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Profile Helper
 * -------------------------------------------------------------------------
 * Adiciona uma aba na tela de Perfis do GLPI para permitir que os direitos
 * do Nextool sejam configurados por perfil (READ/UPDATE/DELETE/PURGE).
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/permissionmanager.class.php';

class PluginNextoolProfile extends Profile {

   public static $rightname = 'profile';

   public static function getTable($classname = null) {
      return 'glpi_profiles';
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item instanceof Profile && $item->getID()) {
         return self::createTabEntry(__('NexTool', 'nextool'));
      }
      return '';
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item instanceof Profile) {
         $profile = new self();
         $profile->showFormNextool((int) $item->getID());
      }
      return true;
   }

   public function getRights($interface = 'central') {
      $rights = parent::getRights($interface);
      unset($rights[CREATE]);
      return $rights;
   }

   private function showFormNextool(int $profiles_id): void {
      if (!$this->can($profiles_id, READ)) {
         return;
      }

      $canEdit = Session::haveRight(self::$rightname, UPDATE);
      PluginNextoolPermissionManager::syncModuleRights();

      echo "<div class='spaced'>";
      if ($canEdit) {
         echo "<form method='post' action='" . static::getFormURL() . "'>";
      }

      $matrixOptions = [
         'title'   => __('Permissões NexTool', 'nextool'),
         'canedit' => $canEdit,
      ];

      $rights = [
         [
            'itemtype' => self::class,
            'label'    => __('Módulos do NexTool', 'nextool'),
            'field'    => PluginNextoolPermissionManager::RIGHT_MODULES,
         ],
         [
            'itemtype' => self::class,
            'label'    => __('Abas administrativas (Licença, Contato, Logs)', 'nextool'),
            'field'    => PluginNextoolPermissionManager::RIGHT_ADMIN_TABS,
         ],
      ];

      $moduleRights = PluginNextoolPermissionManager::getModuleRightsMetadata();
      foreach ($moduleRights as $moduleRight) {
         $rights[] = [
            'itemtype' => self::class,
            'label'    => sprintf(__('Módulo: %s', 'nextool'), $moduleRight['label']),
            'field'    => $moduleRight['right'],
         ];
      }

      echo "<div id='nextool-rights-matrix'>";
      $this->displayRightsChoiceMatrix($rights, $matrixOptions);
      echo "</div>";

      if ($canEdit) {
         echo Html::hidden('id', ['value' => $profiles_id]);
         echo "<div class='text-center'>";
         echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
         echo '</div>';
         Html::closeForm();
      }
      echo '</div>';
   }
}


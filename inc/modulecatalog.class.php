<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolModuleCatalog {

   private const MODULES = [
      'helloworld' => [
         'name'        => 'Hello World (PoC)',
         'description' => 'Demonstração da distribuição remota de módulos via ContainerAPI.',
         'version'     => '1.0.0',
         'icon'        => 'ti ti-code',
         'billing_tier'=> 'FREE',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => 'RITEC',
      ],
      'aiassist' => [
         'name'        => 'AI Assist',
         'description' => 'Resumos, respostas e análise de sentimento com IA diretamente nos tickets.',
         'version'     => '1.3.6-beta',
         'icon'        => 'ti ti-robot',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => 'RITEC',
      ],
      'autentique' => [
         'name'        => 'Autentique',
         'description' => 'Envio de documentos para assinatura digital dentro do fluxo de tickets.',
         'version'     => '1.9.0',
         'icon'        => 'ti ti-signature',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => 'Richard Loureiro',
      ],
      'mailinteractions' => [
         'name'        => 'Mail Interactions',
         'description' => 'Aprovação/rejeição, validação de solução e pesquisas direto por e-mail.',
         'version'     => '2.0.1',
         'icon'        => 'ti ti-mail',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => 'Richard Loureiro',
      ],
      'pendingsurvey' => [
         'name'        => 'Pending Survey',
         'description' => 'Bloqueia o catálogo enquanto houver pesquisas de satisfação pendentes.',
         'version'     => '1.0.0',
         'icon'        => 'ti ti-message-question',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => 'Richard Loureiro',
      ],
      'smartassign' => [
         'name'        => 'Smart Assign',
         'description' => 'Distribuição inteligente de tickets por categoria com balanceamento ou rodízio.',
         'version'     => '1.2.0',
         'icon'        => 'ti ti-user-check',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => 'Richard Loureiro',
      ],
   ];

   public static function all(): array {
      return self::MODULES;
   }

   public static function find(string $moduleKey): ?array {
      return self::MODULES[$moduleKey] ?? null;
   }
}



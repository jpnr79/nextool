<?php
/**
 * Endpoint AJAX para ações do AI Assist (resumo, sugestão, sentimento)
 */

// O includes.php já foi carregado pelo roteador module_ajax.php

Toolbox::logInFile('plugin_nextool_aiassist', '[ENDPOINT] Requisição AJAX recebida - Action: ' . ($_POST['action'] ?? 'não definido'));

header('Content-Type: application/json; charset=UTF-8');

// Verificar sessão válida
if (!isset($_SESSION['glpiID']) || $_SESSION['glpiID'] <= 0) {
   http_response_code(401);
   echo json_encode([
      'success' => false,
      'message' => 'Sessão inválida'
   ]);
   exit;
}

// Carrega classes do plugin
require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';

// Obtém instância do módulo via ModuleManager
$manager = PluginNextoolModuleManager::getInstance();
$moduleInstance = $manager->getModule('aiassist');

if (!$moduleInstance) {
   http_response_code(500);
   echo json_encode([
      'success' => false,
      'message' => 'Módulo AI Assist não encontrado'
   ]);
   exit;
}

function aiassist_send_response(array $payload, int $status = 200) {
   if ($status !== 200) {
      http_response_code($status);
   }
   $payload['next_csrf_token'] = Session::getNewCSRFToken();
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
}

// Tratamento global de erros
try {
   if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      aiassist_send_response([
         'success' => false,
         'message' => 'Método não permitido'
      ], 405);
   }

   $csrf = $_POST['_glpi_csrf_token'] ?? '';
   Session::validateCSRF($csrf);

   $ticketId = (int)($_POST['tickets_id'] ?? 0);
   $action   = trim($_POST['action'] ?? '');

   if ($ticketId <= 0 || $action === '') {
      aiassist_send_response([
         'success' => false,
         'message' => 'Parâmetros inválidos'
      ], 400);
   }

   // Verificar se o usuário tem acesso ao ticket
   $ticket = new Ticket();
   if (!$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) {
      aiassist_send_response([
         'success' => false,
         'message' => 'Você não possui acesso a este chamado'
      ], 403);
   }

   if (!$ticket->canUpdateItem()) {
      aiassist_send_response([
         'success' => false,
         'message' => 'Você não possui permissão para executar ações de IA neste chamado'
      ], 403);
   }

   // Usa a instância carregada pelo ModuleManager
   $module = $moduleInstance;
   $userId = (int)Session::getLoginUserID();

switch ($action) {
   case PluginNextoolAiassist::FEATURE_SUMMARY:
      $blockReason = '';
      if (!$module->checkFeatureAvailability(PluginNextoolAiassist::FEATURE_SUMMARY, $ticketId, $userId, $blockReason)) {
         aiassist_send_response([
            'success' => false,
            'message' => $blockReason,
            'block_reason' => $blockReason
         ], 409);
      }

      $result = $module->getSummaryService()->generate($ticketId, $userId);
      if (!empty($result['success'])) {
         // Recupera os dados consolidados do resumo após salvar, garantindo consistência
         $ticketData = $module->getTicketData($ticketId);
         $summaryPayload = [
            'summary_text' => $ticketData['summary_text'] ?? ($result['content'] ?? ''),
            'updated_at'   => !empty($ticketData['last_summary_at'])
               ? Html::convDateTime($ticketData['last_summary_at'])
               : Html::convDateTime(date('Y-m-d H:i:s')),
         ];

         aiassist_send_response([
            'success' => true,
            'feature' => PluginNextoolAiassist::FEATURE_SUMMARY,
            'message' => __('Resumo gerado com sucesso.', 'nextool'),
            'data' => $summaryPayload
         ]);
      }

      aiassist_send_response([
         'success' => false,
         'message' => $result['error'] ?? __('Falha ao gerar resumo.', 'nextool')
      ], 500);
      break;

   case PluginNextoolAiassist::FEATURE_REPLY:
      $blockReason = '';
      if (!$module->checkFeatureAvailability(PluginNextoolAiassist::FEATURE_REPLY, $ticketId, $userId, $blockReason)) {
         aiassist_send_response([
            'success' => false,
            'message' => $blockReason,
            'block_reason' => $blockReason
         ], 409);
      }

      $result = $module->getReplyService()->suggest($ticketId, $userId);
      if (!empty($result['success'])) {
         aiassist_send_response([
            'success' => true,
            'feature' => PluginNextoolAiassist::FEATURE_REPLY,
            'message' => __('Sugestão gerada com sucesso.', 'nextool'),
            'data' => $result['suggestion'] ?? ''
         ]);
      }

      aiassist_send_response([
         'success' => false,
         'message' => $result['error'] ?? __('Falha ao gerar sugestão.', 'nextool')
      ], 500);
      break;

   case PluginNextoolAiassist::FEATURE_SENTIMENT:
      $blockReason = '';
      if (!$module->checkFeatureAvailability(PluginNextoolAiassist::FEATURE_SENTIMENT, $ticketId, $userId, $blockReason)) {
         aiassist_send_response([
            'success' => false,
            'message' => $blockReason,
            'block_reason' => $blockReason
         ], 409);
      }

      $result = $module->getSentimentService()->analyze($ticketId, $userId);
      if (!empty($result['success'])) {
         $analysisData = $result['parsed'] ?? $result['analysis'] ?? [];
         if (!empty($analysisData)) {
            $analysisData['updated_at'] = Html::convDateTime(date('Y-m-d H:i:s'));
         }

         aiassist_send_response([
            'success' => true,
            'feature' => PluginNextoolAiassist::FEATURE_SENTIMENT,
            'message' => __('Sentimento recalculado com sucesso.', 'nextool'),
            'data' => $analysisData
         ]);
      }

      aiassist_send_response([
         'success' => false,
         'message' => $result['error'] ?? __('Falha ao analisar sentimento.', 'nextool')
      ], 500);
      break;

   default:
      aiassist_send_response([
         'success' => false,
         'message' => __('Ação inválida.', 'nextool')
      ], 400);
}

} catch (Throwable $e) {
   error_log(sprintf(
      '[AI Assist] Erro: %s em %s:%d',
      $e->getMessage(),
      $e->getFile(),
      $e->getLine()
   ));
   
   aiassist_send_response([
      'success' => false,
      'message' => 'Erro: ' . $e->getMessage()
   ], 500);
}

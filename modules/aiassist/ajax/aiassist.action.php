<?php
/**
 * Endpoint AJAX para ações do AI Assist (resumo, sugestão, sentimento)
 */

// O includes.php já foi carregado pelo roteador module_ajax.php

// Função auxiliar para renderizar resumo em HTML
if (!function_exists('plugin_nextool_aiassist_render_summary_html')) {
   function plugin_nextool_aiassist_render_summary_html($text) {
      $text = (string)$text;
      if ($text === '') {
         return '';
      }

      // Normaliza quebras de linha
      $text = str_replace(["\r\n", "\r"], "\n", $text);
      
      // Remove TODAS quebras múltiplas - mantém apenas 1
      $text = preg_replace('/\n+/', "\n", $text);

      // Escapa HTML para evitar XSS
      $safe = Html::entities_deep($text);

      // Converte **texto** em <strong>texto</strong>
      $safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);

      // Adiciona quebra dupla antes de cada tópico 2-9 para espaçamento
      $safe = preg_replace('/\n(\d+\.\s+<strong>)/', "\n\n$1", $safe);
      
      // Remove quebras no início
      $safe = ltrim($safe, "\n");

      // Converte quebras de linha em <br>
      $safe = nl2br($safe, false);
      
      // LIMPEZA FINAL: Remove <br> duplicados (mantém no máximo 2 consecutivos)
      $safe = preg_replace('/(<br>\s*){3,}/', '<br><br>', $safe);
      
      // Remove TODOS os \n literais restantes
      $safe = str_replace("\n", '', $safe);

      return $safe;
   }
}

require_once GLPI_ROOT . '/plugins/nextool/inc/logger.php';
nextool_log('plugin_nextool_aiassist', '[ENDPOINT] Requisição AJAX recebida - Action: ' . ($_POST['action'] ?? 'não definido'));

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
require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';

// Verifica permissão de visualização do módulo
if (!PluginNextoolPermissionManager::canViewModule('aiassist')) {
   http_response_code(403);
   echo json_encode([
      'success' => false,
      'message' => 'Você não tem permissão para usar este módulo.'
   ]);
   exit;
}

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

      // Aceitar parâmetro force para forçar nova geração
      $force = !empty($_POST['force']);
      
      $result = $module->getSummaryService()->generate($ticketId, $userId, [
         'force' => $force
      ]);
      
      if (!empty($result['success'])) {
         // Recupera os dados consolidados do resumo após salvar, garantindo consistência
         $ticketData = $module->getTicketData($ticketId);
         $summaryText = $ticketData['summary_text'] ?? ($result['content'] ?? '');
         
         // Formatar HTML no backend para garantir consistência
         $summaryHtml = '';
         if ($summaryText !== '') {
            if (function_exists('plugin_nextool_aiassist_render_summary_html')) {
               $summaryHtml = plugin_nextool_aiassist_render_summary_html($summaryText);
            } else {
               $summaryHtml = nl2br(Html::entities_deep($summaryText));
            }
         }
         
         $summaryPayload = [
            'summary_text' => $summaryText,
            'summary_html' => $summaryHtml,
            'is_html' => true,  // Indica que summary_html está pronto para renderizar
            'updated_at'   => !empty($ticketData['last_summary_at'])
               ? Html::convDateTime($ticketData['last_summary_at'])
               : Html::convDateTime(date('Y-m-d H:i:s')),
         ];
         
         // Indicar se veio de cache
         if (!empty($result['from_cache'])) {
            $summaryPayload['from_cache'] = true;
            $summaryPayload['cached_at'] = !empty($result['cached_at']) 
               ? Html::convDateTime($result['cached_at'])
               : Html::convDateTime($ticketData['last_summary_at'] ?? date('Y-m-d H:i:s'));
            $responseMessage = __('Resumo recuperado do cache (sem alterações recentes).', 'nextool');
         } else {
            $responseMessage = __('Resumo gerado com sucesso.', 'nextool');
         }

         aiassist_send_response([
            'success' => true,
            'feature' => PluginNextoolAiassist::FEATURE_SUMMARY,
            'message' => $responseMessage,
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

      // Aceitar parâmetro force para forçar nova geração
      $force = !empty($_POST['force']);
      
      $result = $module->getReplyService()->suggest($ticketId, $userId, [
         'force' => $force
      ]);
      
      if (!empty($result['success'])) {
         // Obter texto da resposta (pode vir do cache ou ser novo)
         $replyText = $result['content'] ?? '';
         
         // Para sugestão de resposta: manter quebras EXATAMENTE como vieram da API
         // (diferente do resumo que remove quebras múltiplas)
         $replyHtml = '';
         if ($replyText !== '') {
            // Escapa HTML para segurança
            $safe = Html::entities_deep($replyText);
            // Converte quebras de linha em <br> PRESERVANDO TODAS as quebras
            // (se a API mandou \n\n, vira <br><br>)
            $replyHtml = nl2br($safe, false);
         }
         
         $responseData = [
            'success' => true,
            'feature' => PluginNextoolAiassist::FEATURE_REPLY,
            'message' => __('Sugestão gerada com sucesso.', 'nextool'),
            'data' => $replyText,          // Texto plano (para inserir no editor)
            'reply_html' => $replyHtml,    // HTML formatado (para exibir no modal)
            'is_html' => true              // Indica que reply_html está pronto para renderizar
         ];
         
         // Indicar se veio de cache
         if (!empty($result['from_cache'])) {
            $ticketData = $module->getTicketData($ticketId);
            $responseData['from_cache'] = true;
            $responseData['cached_at'] = !empty($result['cached_at']) 
               ? Html::convDateTime($result['cached_at'])
               : Html::convDateTime($ticketData['last_reply_at'] ?? date('Y-m-d H:i:s'));
            $responseData['message'] = __('Sugestão recuperada do cache (sem alterações recentes).', 'nextool');
         }
         
         aiassist_send_response($responseData);
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

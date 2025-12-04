<?php
/**
 * Serviço responsável por gerar resumos de chamados.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAiassistSummaryService {

   /** @var PluginNextoolAiassist */
   private $module;

   /** @var PluginNextoolAiassistProviderInterface */
   private $provider;

   public function __construct(PluginNextoolAiassist $module) {
      $this->module = $module;
      $this->provider = $module->getProviderInstance();
   }

   /**
    * Gera um resumo consolidado do chamado.
    *
    * @param int $ticketId
    * @param int $userId
    * @param array $options
    * @return array
    */
   public function generate($ticketId, $userId, array $options = []) {
      Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
         '[SUMMARY] Iniciando geração - Ticket #%d, User #%d',
         $ticketId,
         $userId
      ));
      
      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticketId)) {
         Toolbox::logInFile('plugin_nextool_aiassist', "[SUMMARY] Ticket #$ticketId não encontrado");
         return [
            'success' => false,
            'message' => __('Chamado não encontrado.', 'nextool'),
         ];
      }

      $context = $this->module->buildTicketContext($ticket, $options);
      if (empty($context['text'])) {
         return [
            'success' => false,
            'message' => __('Não há conteúdo suficiente para gerar um resumo.', 'nextool'),
         ];
      }

      // Verificar se deve usar cache (a menos que force=true)
      $force = !empty($options['force']);
      
      if (!$force) {
         $ticketData = $this->module->getTicketData($ticketId);
         $cachedSummary = trim((string)($ticketData['summary_text'] ?? ''));
         $lastSummaryFollowupId = (int)($ticketData['last_summary_followup_id'] ?? 0);
         $currentLastFollowupId = (int)($context['last_followup_id'] ?? 0);
         
         // Se já tem cache E não há novo followup, retornar cache
         if ($cachedSummary !== '' && $lastSummaryFollowupId === $currentLastFollowupId) {
            Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
               '[SUMMARY] Retornando resumo em cache (sem novos followups) - Ticket #%d',
               $ticketId
            ));
            return [
               'success' => true,
               'content' => $cachedSummary,
               'from_cache' => true,
               'cached_at' => $ticketData['last_summary_at'] ?? null
            ];
         }
      }

      $estimatedTokens = $this->module->estimateTokensFromText($context['text']);
      if (!$this->module->hasTokensAvailable($estimatedTokens)) {
         return [
            'success' => false,
            'message' => __('Limite de tokens excedido. Ajuste o saldo ou aguarde o próximo ciclo.', 'nextool'),
         ];
      }

      $summaryPrompt = $this->buildSummaryPrompt($ticket, $context['text']);

      $response = $this->provider->chat([
         [
            'role' => 'system',
            'content' => 'Resuma chamados em português do Brasil. Responda em formato estruturado, com foco em contexto, ações, pendências, próximos passos e observações relevantes.'
         ],
         [
            'role' => 'user',
            'content' => $summaryPrompt
         ],
      ], [
         'model' => $this->module->getFeatureModel(PluginNextoolAiassist::FEATURE_SUMMARY),
         'max_tokens' => 600,
         'temperature' => 0.2,
         'metadata' => [
            'feature' => PluginNextoolAiassist::FEATURE_SUMMARY,
            'ticket_id' => $ticketId,
         ]
      ]);

      $logPayload = [
         'tickets_id' => $ticketId,
         'users_id' => $userId,
         'feature' => PluginNextoolAiassist::FEATURE_SUMMARY,
         'success' => $response['success'] ?? false,
         'tokens_prompt' => $response['tokens_prompt'] ?? $estimatedTokens,
         'tokens_completion' => $response['tokens_completion'] ?? 0,
         'payload_hash' => $context['payload_hash'] ?? null,
         'error_message' => $response['error'] ?? null
      ];
      $this->module->logFeatureRequest($logPayload);

      if (!empty($response['success'])) {
         $this->module->saveSummaryData($ticketId, [
            'summary_text' => $response['content'],
            'summary_hash' => $context['hash'],
            'last_followup_id' => $context['last_followup_id'],
            'payload_hash' => $context['payload_hash'],
         ]);
      }

      return $response;
   }

   /**
    * Monta o prompt específico do resumo.
    */
   private function buildSummaryPrompt(Ticket $ticket, $contextText) {
      $ticketId = (int)$ticket->getID();
      $priority = $ticket->fields['priority'] ?? '';
      $category = $ticket->fields['itilcategories_id'] ?? '';

      $metadata = sprintf(
         "Chamado #%d | Prioridade: %s | Categoria: %s",
         $ticketId,
         $priority,
         $category
      );

      return $metadata . "\n\n" .
         "Gere um resumo do chamado, em **português do Brasil**, seguindo EXATAMENTE o formato abaixo (markdown):\n\n" .
         "1. **Contexto:** ...\n" .
         "2. **Ações:** ...\n" .
         "3. **Pendências:** ...\n" .
         "4. **Próximos passos:** ...\n" .
         "5. **Observações relevantes:** ...\n\n" .
         "Regras importantes:\n" .
         "- Use frases curtas e objetivas.\n" .
         "- Preencha todos os 5 itens sempre que possível (use \"Sem pendências\" ou \"Sem observações relevantes\" quando não houver informação).\n" .
         "- NÃO inclua título, explicações adicionais, comentários fora da estrutura acima, nem traduções para outros idiomas.\n" .
         "- Comece diretamente pelo item 1.\n\n" .
         "A seguir está o contexto completo do chamado para você resumir:\n\n" .
         $contextText;
   }
}


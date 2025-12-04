<?php
/**
 * Serviço responsável por sugerir respostas ao solicitante.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAiassistReplyService {

   /** @var PluginNextoolAiassist */
   private $module;

   /** @var PluginNextoolAiassistProviderInterface */
   private $provider;

   public function __construct(PluginNextoolAiassist $module) {
      $this->module = $module;
      $this->provider = $module->getProviderInstance();
   }

   /**
    * Gera sugestão de resposta para o ticket.
    *
    * @param int $ticketId
    * @param int $userId
    * @param array $options
    * @return array
    */
   public function suggest($ticketId, $userId, array $options = []) {
      Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
         '[REPLY] Iniciando sugestão - Ticket #%d, User #%d',
         $ticketId,
         $userId
      ));
      
      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticketId)) {
         Toolbox::logInFile('plugin_nextool_aiassist', "[REPLY] Ticket #$ticketId não encontrado");
         return [
            'success' => false,
            'message' => __('Chamado não encontrado.', 'nextool'),
         ];
      }

      $context = $this->module->buildTicketContext($ticket, [
         'limit_followups' => 6
      ]);

      if (empty($context['text'])) {
         return [
            'success' => false,
            'message' => __('Não há histórico suficiente para sugerir uma resposta.', 'nextool'),
         ];
      }

      // Verificar se deve usar cache (a menos que force=true)
      $force = !empty($options['force']);
      
      if (!$force) {
         $ticketData = $this->module->getTicketData($ticketId);
         $cachedReply = trim((string)($ticketData['reply_text'] ?? ''));
         $lastReplyFollowupId = (int)($ticketData['last_reply_followup_id'] ?? 0);
         $currentLastFollowupId = (int)($context['last_followup_id'] ?? 0);
         
         // Se já tem cache E não há novo followup, retornar cache
         if ($cachedReply !== '' && $lastReplyFollowupId === $currentLastFollowupId) {
            Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
               '[REPLY] Retornando sugestão em cache (sem novos followups) - Ticket #%d',
               $ticketId
            ));
            return [
               'success' => true,
               'content' => $cachedReply,
               'from_cache' => true,
               'cached_at' => $ticketData['last_reply_at'] ?? null
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

      // Obtém nome do analista para assinatura dinâmica
      $analystName = $this->module->getUserDisplayName($userId);
      
      $tone = $options['tone'] ?? __('profissional e cordial', 'nextool');
      $instructions = sprintf(
         "Com base no histórico abaixo, redija uma resposta %s para o solicitante, informando status atual e próximos passos. Inclua saudação inicial e FINALIZE com:\n\nAtenciosamente,\n%s",
         $tone,
         $analystName
      );

      $response = $this->provider->chat([
         [
            'role' => 'system',
            'content' => 'Responda em português do Brasil e mantenha tom empático e claro.'
         ],
         [
            'role' => 'user',
            'content' => $instructions . "\n\n" . $context['text']
         ],
      ], [
         'model' => $this->module->getFeatureModel(PluginNextoolAiassist::FEATURE_REPLY),
         'max_tokens' => 500,
         'temperature' => 0.4,
         'metadata' => [
            'feature' => PluginNextoolAiassist::FEATURE_REPLY,
            'ticket_id' => $ticketId,
         ]
      ]);

      $this->module->logFeatureRequest([
         'tickets_id' => $ticketId,
         'users_id' => $userId,
         'feature' => PluginNextoolAiassist::FEATURE_REPLY,
         'success' => $response['success'] ?? false,
         'tokens_prompt' => $response['tokens_prompt'] ?? $estimatedTokens,
         'tokens_completion' => $response['tokens_completion'] ?? 0,
         'payload_hash' => $context['payload_hash'] ?? null,
         'error_message' => $response['error'] ?? null
      ]);

      if (!empty($response['success'])) {
         $this->module->saveReplyData($ticketId, [
            'reply_text' => $response['content'],
            'last_followup_id' => $context['last_followup_id']
         ]);
      }

      return $response;
   }
}

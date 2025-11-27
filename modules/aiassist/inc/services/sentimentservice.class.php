<?php
/**
 * Serviço responsável por analisar sentimento e urgência.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAiassistSentimentService {

   /** @var PluginNextoolAiassist */
   private $module;

   /** @var PluginNextoolAiassistProviderInterface */
   private $provider;

   public function __construct(PluginNextoolAiassist $module) {
      $this->module = $module;
      $this->provider = $module->getProviderInstance();
   }

   /**
    * Executa análise de sentimento/urgência.
    *
    * @param int $ticketId
    * @param int $userId
    * @param array $options
    * @return array
    */
   public function analyze($ticketId, $userId = 0, array $options = []) {
      Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
         '[SENTIMENT] Iniciando análise - Ticket #%d, User #%d',
         $ticketId,
         $userId
      ));
      
      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticketId)) {
         Toolbox::logInFile('plugin_nextool_aiassist', "[SENTIMENT] Ticket #$ticketId não encontrado");
         return [
            'success' => false,
            'message' => __('Chamado não encontrado.', 'nextool'),
         ];
      }

      $title = trim((string)($ticket->fields['name'] ?? ''));
      $description = $this->normalizeTicketDescription($ticket->fields['content'] ?? '');

      if ($title === '' && $description === '') {
         return [
            'success' => false,
            'message' => __('Sem conteúdo na abertura do chamado para analisar.', 'nextool'),
         ];
      }

      $maxChars = (int)($this->module->getSettings()['payload_max_chars'] ?? 6000);
      $maxChars = max(1000, $maxChars);

      $payloadParts = [];
      if ($title !== '') {
         $payloadParts[] = "Título do chamado:\n" . $title;
      }
      if ($description !== '') {
         $payloadParts[] = "Descrição inicial:\n" . $description;
      }

      $payloadText = implode("\n\n", $payloadParts);
      if (mb_strlen($payloadText) > $maxChars) {
         $payloadText = mb_substr($payloadText, 0, $maxChars - 3) . '...';
      }

      $estimatedTokens = $this->module->estimateTokensFromText($payloadText);
      if (!$this->module->hasTokensAvailable($estimatedTokens)) {
         return [
            'success' => false,
            'message' => __('Limite de tokens excedido. Ajuste o saldo ou aguarde o próximo ciclo.', 'nextool'),
         ];
      }

      $instructions = <<<JSON
Você analisará mensagens da abertura de um chamado de suporte. Classifique e responda apenas em JSON com o seguinte formato:
{
  "sentiment_label": "Positivo|Neutro|Negativo|Crítico",
  "sentiment_score": -1.0 a 1.0,
  "urgency_level": "Baixa|Média|Alta|Crítica",
  "rationale": "resumo breve (máx 2 frases)"
}
JSON;

      $response = $this->provider->chat([
         [
            'role' => 'system',
            'content' => 'Você atua como analista de sentimento para tickets de suporte em português do Brasil.'
         ],
         [
            'role' => 'user',
            'content' => $instructions . "\n\n" . $payloadText
         ],
      ], [
         'model' => $this->module->getFeatureModel(PluginNextoolAiassist::FEATURE_SENTIMENT),
         'max_tokens' => 350,
         'temperature' => 0.1,
         'metadata' => [
            'feature' => PluginNextoolAiassist::FEATURE_SENTIMENT,
            'ticket_id' => $ticketId,
         ]
      ]);

      $payloadHash = sha1($ticketId . ':' . $payloadText);

      $this->module->logFeatureRequest([
         'tickets_id' => $ticketId,
         'users_id' => $userId,
         'feature' => PluginNextoolAiassist::FEATURE_SENTIMENT,
         'success' => $response['success'] ?? false,
         'tokens_prompt' => $response['tokens_prompt'] ?? $estimatedTokens,
         'tokens_completion' => $response['tokens_completion'] ?? 0,
         'payload_hash' => $payloadHash,
         'error_message' => $response['error'] ?? null
      ]);

      if (!empty($response['success'])) {
         $rawContent = $response['content'] ?? '';
         $decoded = $this->decodeSentimentPayload($rawContent);
         if (is_array($decoded)) {
            $lastFollowupId = $this->module->getLatestFollowupId($ticketId);
            $this->module->saveSentimentData($ticketId, [
               'sentiment_label' => $decoded['sentiment_label'] ?? null,
               'sentiment_score' => isset($decoded['sentiment_score']) ? (float)$decoded['sentiment_score'] : null,
               'urgency_level'   => $decoded['urgency_level'] ?? null,
               'sentiment_rationale' => $decoded['rationale'] ?? null,
               'last_followup_id'=> $lastFollowupId,
            ]);
            $response['parsed'] = $decoded;
            $response['analysis'] = $decoded;
            
            Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
               '[SENTIMENT] ✅ Sucesso - Ticket #%d, Sentimento: %s, Urgência: %s',
               $ticketId,
               $decoded['sentiment_label'] ?? 'N/A',
               $decoded['urgency_level'] ?? 'N/A'
            ));
         } else {
            $response['success'] = false;
            $response['error'] = __('Não foi possível interpretar a resposta da IA.', 'nextool');
            Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
               '[SENTIMENT] ❌ Erro ao interpretar resposta - Ticket #%d. Conteúdo bruto: %s',
               $ticketId,
               mb_substr($rawContent, 0, 500)
            ));
         }
      }

      return $response;
   }

   /**
    * Normaliza descrição da abertura removendo HTML e espaços extras.
    *
    * @param string $htmlContent
    * @return string
    */
   private function normalizeTicketDescription($htmlContent) {
      $text = (string)$htmlContent;
      if ($text === '') {
         return '';
      }

      $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text);
      $text = preg_replace('/<\/p>/i', "</p>\n", $text);
      $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $text = strip_tags($text);
      $text = preg_replace('/\r\n?/', "\n", $text);
      $text = preg_replace('/\n{3,}/', "\n\n", $text);

      return trim($text);
   }

   /**
    * Tenta interpretar o JSON retornado pela IA, mesmo que venha rodeado de texto.
    *
    * @param string $rawContent
    * @return array|null
    */
   private function decodeSentimentPayload($rawContent) {
      if (!is_string($rawContent) || $rawContent === '') {
         return null;
      }

      $rawContent = trim($rawContent);
      $decoded = json_decode($rawContent, true);
      if (is_array($decoded)) {
         return $decoded;
      }

      if (preg_match('/\{.*\}/sU', $rawContent, $matches)) {
         $jsonCandidate = $matches[0];
         $decoded = json_decode($jsonCandidate, true);
         if (is_array($decoded)) {
            return $decoded;
         }
      }

      return null;
   }
}


<?php
/**
 * Serviço responsável por analisar sentimento e urgência.
 *
 * Versão aprimorada:
 * - Considera título, descrição inicial E histórico recente do solicitante;
 * - Usa escala de 0 a 10 para o score (0 = extremamente negativo, 10 = extremamente positivo);
 * - Foca na evolução do humor ao longo das últimas interações.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once GLPI_ROOT . '/plugins/nextool/inc/logger.php';

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
    * Executa análise de sentimento/urgência considerando abertura e histórico recente.
    *
    * @param int $ticketId
    * @param int $userId
    * @param array $options
    * @return array
    */
   public function analyze($ticketId, $userId = 0, array $options = []) {
      nextool_log('plugin_nextool_aiassist', sprintf(
         '[SENTIMENT] Iniciando análise aprimorada - Ticket #%d, User #%d',
         $ticketId,
         $userId
      ));
      
      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticketId)) {
         nextool_log('plugin_nextool_aiassist', "[SENTIMENT] Ticket #$ticketId não encontrado");
         return [
            'success' => false,
            'message' => __('Chamado não encontrado.', 'nextool'),
         ];
      }

      // 1. Dados básicos (Título e Descrição)
      $title = trim((string)(($ticket->fields['name'] ?? '')));
      $description = $this->normalizeTicketDescription(($ticket->fields['content'] ?? ''));

      // 2. Histórico recente do solicitante (últimas 5 interações públicas)
      $requesterUpdates = [];
      $requesterId = (int)($ticket->fields['users_id_recipient'] ?? 0);

      if ($requesterId > 0) {
         global $DB;
         $iterator = $DB->request([
            'FROM'   => 'glpi_itilfollowups',
            'WHERE'  => [
               'items_id'   => $ticketId,
               'itemtype'   => 'Ticket',
               'is_private' => 0,
               'users_id'   => $requesterId
            ],
            'ORDER'  => 'date DESC',
            'LIMIT'  => 5
         ]);

         foreach ($iterator as $row) {
            $cleanContent = $this->normalizeTicketDescription($row['content']);
            if ($cleanContent !== '') {
               $requesterUpdates[] = sprintf("[%s] %s", $row['date'], $cleanContent);
            }
         }

         // Ordem cronológica para a IA entender evolução (do mais antigo para o mais recente)
         $requesterUpdates = array_reverse($requesterUpdates);
      }

      // Validação mínima de conteúdo
      if ($title === '' && $description === '' && empty($requesterUpdates)) {
         return [
            'success' => false,
            'message' => __('Sem conteúdo suficiente (título, descrição ou interações) para analisar.', 'nextool'),
         ];
      }

      // 3. Construção do payload com priorização de histórico
      $maxChars = (int)($this->module->getSettings()['payload_max_chars'] ?? 6000);
      $maxChars = max(1000, $maxChars);

      $historyText = '';
      if (!empty($requesterUpdates)) {
         $historyText = "Histórico recente do solicitante (considere a evolução do sentimento aqui):\n" . implode("\n---\n", $requesterUpdates);
      }

      // Priorizamos: Título > Histórico recente > Descrição inicial
      $reservedChars = mb_strlen($title) + mb_strlen($historyText) + 200;
      $availableForDesc = $maxChars - $reservedChars;

      if ($availableForDesc < 500) {
         $availableForDesc = 500;
      }

      if (mb_strlen($description) > $availableForDesc) {
         $description = mb_substr($description, 0, $availableForDesc) . '... [truncado]';
      }

      $payloadParts = [];
      if ($title !== '') {
         $payloadParts[] = "Título do chamado:\n" . $title;
      }
      if ($description !== '') {
         $payloadParts[] = "Descrição inicial:\n" . $description;
      }
      if ($historyText !== '') {
         $payloadParts[] = $historyText;
      }

      $payloadText = implode("\n\n", $payloadParts);

      // Corte de segurança
      if (mb_strlen($payloadText) > $maxChars) {
         $payloadText = mb_substr($payloadText, 0, $maxChars - 3) . '...';
      }

      // Estimativa de tokens e verificação de quota
      $estimatedTokens = $this->module->estimateTokensFromText($payloadText);
      if (!$this->module->hasTokensAvailable($estimatedTokens)) {
         return [
            'success' => false,
            'message' => __('Limite de tokens excedido. Ajuste o saldo ou aguarde o próximo ciclo.', 'nextool'),
         ];
      }

      // Prompt atualizado para escala 0–10 e foco na evolução do humor
      $instructions = <<<JSON
Você analisará a abertura de um chamado de suporte e as interações recentes do solicitante.
Avalie a evolução do humor. Se o usuário estava calmo e ficou irritado no final, considere o estado ATUAL (irritado).
Classifique e responda apenas em JSON com o seguinte formato:
{
  "sentiment_label": "Positivo|Neutro|Negativo|Crítico",
  "sentiment_score": 0 a 10 (onde 0 é extremamente negativo e 10 extremamente positivo),
  "urgency_level": "Baixa|Média|Alta|Crítica",
  "rationale": "resumo breve (máx 2 frases) justificando com base no contexto atual"
}
JSON;

      $response = $this->provider->chat([
         [
            'role' => 'system',
            'content' => 'Analise o sentimento de tickets em português do Brasil.'
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
            
            nextool_log('plugin_nextool_aiassist', sprintf(
               '[SENTIMENT] ✅ Sucesso - Ticket #%d, Sentimento: %s, Urgência: %s',
               $ticketId,
               $decoded['sentiment_label'] ?? 'N/A',
               $decoded['urgency_level'] ?? 'N/A'
            ));
         } else {
            $response['success'] = false;
            $response['error'] = __('Não foi possível interpretar a resposta da IA.', 'nextool');
            nextool_log('plugin_nextool_aiassist', sprintf(
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


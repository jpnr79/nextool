<?php
/**
 * Implementação do provedor OpenAI para o módulo AI Assist.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/providerinterface.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/logger.php';

class PluginNextoolAiassistOpenAiProvider implements PluginNextoolAiassistProviderInterface {

   /** @var string */
   private $apiKey;

   /** @var string */
   private $model;

   /** @var int */
   private $timeout;

   /** @var string */
   private $endpoint;

   public function __construct(array $config) {
      $this->apiKey = $config['api_key'] ?? '';
      $this->model = $config['model'] ?? 'gpt-4o-mini';
      $this->timeout = max(5, (int)($config['timeout_seconds'] ?? 25));
      $this->endpoint = rtrim($config['openai_endpoint'] ?? 'https://api.openai.com/v1/chat/completions', '/');
   }

   /**
    * {@inheritdoc}
    */
   public function chat(array $messages, array $options = []): array {
      if (empty($this->apiKey)) {
         return [
            'success' => false,
            'content' => '',
            'tokens_prompt' => 0,
            'tokens_completion' => 0,
            'raw' => [],
            'error' => __('Chave da OpenAI não configurada.', 'nextool')
         ];
      }

      $model = $options['model'] ?? $this->model;
      $temperature = isset($options['temperature']) ? (float)$options['temperature'] : 0.2;
      $maxTokens = isset($options['max_tokens']) ? (int)$options['max_tokens'] : 600;
      $metadata = $options['metadata'] ?? null;

      try {
         $client = new \GuzzleHttp\Client([
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
         ]);

         $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
         ];
         
         // Adiciona metadata apenas se não for vazio e for um objeto (array associativo)
         // O endpoint /v1/chat/completions só aceita "metadata" quando "store" está habilitado.
         // Como não habilitamos `store`, omitimos o campo para evitar erros 400 da OpenAI.
         
         $body = array_filter($body, function($value) {
            return $value !== null && $value !== '';
         });

         $response = $client->request('POST', $this->endpoint, [
            'headers' => [
               'Authorization' => 'Bearer ' . $this->apiKey,
               'Content-Type'  => 'application/json',
            ],
            'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
         ]);

         $status = $response->getStatusCode();
         $payload = json_decode((string)$response->getBody(), true);

         if ($status < 200 || $status >= 300) {
            return [
               'success' => false,
               'content' => '',
               'tokens_prompt' => 0,
               'tokens_completion' => 0,
               'raw' => $payload,
               'error' => sprintf(__('OpenAI respondeu com status %s.', 'nextool'), $status)
            ];
         }

         $content = trim($payload['choices'][0]['message']['content'] ?? '');

         return [
            'success' => ($content !== ''),
            'content' => $content,
            'tokens_prompt' => (int)($payload['usage']['prompt_tokens'] ?? 0),
            'tokens_completion' => (int)($payload['usage']['completion_tokens'] ?? 0),
            'raw' => $payload,
            'error' => $content === '' ? __('Resposta vazia da OpenAI.', 'nextool') : null
         ];
      } catch (\GuzzleHttp\Exception\RequestException $e) {
         $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
         $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
         $decodedError = json_decode($body, true);
         $apiMessage = $decodedError['error']['message'] ?? null;

         nextool_log('plugin_nextool_aiassist', sprintf(
            '[OPENAI] RequestException status=%s body=%s',
            $status ?: 'n/a',
            $body
         ));

         return [
            'success' => false,
            'content' => '',
            'tokens_prompt' => 0,
            'tokens_completion' => 0,
            'raw' => ['status' => $status, 'body' => $body],
            'error' => $apiMessage ?: __('Falha ao conectar na API da OpenAI. Verifique a chave e permissões.', 'nextool')
         ];
      } catch (\Throwable $e) {
         nextool_log('plugin_nextool_aiassist', sprintf(
            '[OPENAI] Erro inesperado: %s',
            $e->getMessage()
         ));

         return [
            'success' => false,
            'content' => '',
            'tokens_prompt' => 0,
            'tokens_completion' => 0,
            'raw' => [],
            'error' => $e->getMessage()
         ];
      }
   }
}


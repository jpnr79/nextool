<?php
/**
 * Interface padrão para provedores de IA do módulo AI Assist.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

interface PluginNextoolAiassistProviderInterface {

   /**
    * Executa uma requisição de chat/compleção ao provedor.
    *
    * @param array $messages Mensagens no formato OpenAI ([['role' => 'system', 'content' => '...'], ...])
    * @param array $options  Opções adicionais (model, temperature, max_tokens, timeout, metadata)
    * @return array {
    *    success: bool,
    *    content: string,
    *    tokens_prompt: int,
    *    tokens_completion: int,
    *    raw: array,
    *    error: string|null
    * }
    */
   public function chat(array $messages, array $options = []): array;
}


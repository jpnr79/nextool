<?php
if (!defined('GLPI_ROOT')) {
   include('../../../../../inc/includes.php');
}

if (!function_exists('plugin_nextool_aiassist_clean_initial_description')) {
   function plugin_nextool_aiassist_clean_initial_description($html) {
      $text = (string)$html;
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
}

if (!function_exists('plugin_nextool_aiassist_slug')) {
   function plugin_nextool_aiassist_slug($value) {
      $value = (string)$value;
      if ($value === '') {
         return '';
      }

      $raw = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
      if ($raw === false) {
         $raw = $value;
      }

      $raw = strtolower($raw);
      $raw = preg_replace('/[^a-z0-9]+/', '-', $raw);

      return trim($raw, '-');
   }
}

if (!function_exists('plugin_nextool_aiassist_render_summary_html')) {
   /**
    * Renderiza um resumo simples em HTML seguro, preservando negrito (**texto**) e quebras de linha.
    */
   function plugin_nextool_aiassist_render_summary_html($text) {
      $text = (string)$text;
      if ($text === '') {
         return '';
      }

      // Normaliza quebras de linha
      $text = str_replace(["\r\n", "\r"], "\n", $text);

      // Escapa HTML para evitar XSS
      $safe = Html::entities_deep($text);

      // Converte **texto** em <strong>texto</strong>
      $safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);

      // Converte quebras de linha em <br>
      $safe = nl2br($safe);

      return $safe;
   }
}

$ticketId = (int)$ticket->getID();
$userId = (int)Session::getLoginUserID();
$csrfToken = Session::getNewCSRFToken();

$ticketData = $module->getTicketData($ticketId);
$settings = $module->getSettings();

// Resumo do chamado
$summaryText = trim((string)($ticketData['summary_text'] ?? ''));
$summaryDisplayHtml = $summaryText !== ''
   ? plugin_nextool_aiassist_render_summary_html($summaryText)
   : '';
$lastSummaryAt = $ticketData['last_summary_at'] ?? ($ticketData['date_mod'] ?? null);
$summaryEnabled = !empty($settings['feature_summary_enabled']);
$summaryDisabledMsg = $summaryEnabled
   ? $module->getFeatureBlockReason(PluginNextoolAiassist::FEATURE_SUMMARY, $ticketId, $userId)
   : __('A funcionalidade de resumo está desabilitada nas configurações do módulo.', 'nextool');

// Sentimento
$sentimentLabel = $ticketData['sentiment_label'] ?? null;
$sentimentScore = isset($ticketData['sentiment_score']) ? (float)$ticketData['sentiment_score'] : null;
$sentimentRationale = trim((string)($ticketData['sentiment_rationale'] ?? ''));
$lastSentimentAt = $ticketData['last_sentiment_at'] ?? ($ticketData['date_mod'] ?? null);

$sentimentEnabled = !empty($settings['feature_sentiment_enabled']);
$sentimentDisabledMsg = $sentimentEnabled
   ? $module->getFeatureBlockReason(PluginNextoolAiassist::FEATURE_SENTIMENT, $ticketId, $userId)
   : __('A funcionalidade de análise de sentimento está desabilitada nas configurações do módulo.', 'nextool');

$ticketTitle = trim((string)($ticket->fields['name'] ?? ''));
$descriptionText = plugin_nextool_aiassist_clean_initial_description($ticket->fields['content'] ?? '');
$descriptionDisplay = $descriptionText !== '' ? nl2br(Html::entities_deep($descriptionText)) : '';
$sentimentSlug = $sentimentLabel ? plugin_nextool_aiassist_slug($sentimentLabel) : '';
?>

<div id="aiassist-tab" class="aiassist-simple-tab">
   <input type="hidden" name="_glpi_csrf_token" value="<?php echo Html::entities_deep($csrfToken); ?>">

   <!-- Resumo do Chamado (AI) -->
   <section class="aiassist-panel aiassist-panel--summary mb-4">
      <header class="aiassist-panel__header">
         <div class="aiassist-panel__title">
            <span class="aiassist-panel__eyebrow">
               <i class="ti ti-robot"></i>
               AI Assist
            </span>
            <h3><?php echo __('Resumo do Chamado', 'nextool'); ?></h3>
         </div>

         <div class="aiassist-panel__status aiassist-panel__status--muted" id="aiassist-summary-status">
            <?php if ($lastSummaryAt): ?>
               <i class="ti ti-clock"></i>
               <span><?php printf(__('Último resumo: %s', 'nextool'), Html::convDateTime($lastSummaryAt)); ?></span>
            <?php else: ?>
               <?php echo __('Nenhum resumo gerado ainda.', 'nextool'); ?>
            <?php endif; ?>
         </div>
      </header>

      <div id="aiassist-summary-result" class="aiassist-result aiassist-summary-result">
         <?php if ($summaryDisplayHtml !== ''): ?>
            <pre class="aiassist-summary-result__content"><?php echo $summaryDisplayHtml; ?></pre>
         <?php else: ?>
            <div class="aiassist-summary-result__empty">
               <i class="ti ti-notebook"></i>
               <p><?php echo __('Ainda não existe um resumo consolidado para este chamado.', 'nextool'); ?></p>
               <small><?php echo __('Use o botão abaixo ou o atalho no formulário para gerar o primeiro resumo.', 'nextool'); ?></small>
            </div>
         <?php endif; ?>
      </div>

      <?php if ($summaryEnabled): ?>
         <div class="aiassist-actions">
            <button type="button"
                    class="aiassist-btn"
                    data-aiassist-action="summary"
                    <?php echo $summaryDisabledMsg ? 'data-disabled-message="' . Html::entities_deep($summaryDisabledMsg) . '"' : ''; ?>>
               <i class="ti ti-file-text"></i>
               <span><?php echo $summaryText !== '' ? __('Atualizar resumo', 'nextool') : __('Gerar resumo', 'nextool'); ?></span>
            </button>
            <div id="aiassist-summary-feedback" class="aiassist-feedback is-hidden" aria-live="polite"></div>
         </div>
      <?php else: ?>
         <div class="aiassist-callout">
            <i class="ti ti-lock"></i>
            <div>
               <strong><?php echo __('Funcionalidade de resumo desativada', 'nextool'); ?></strong>
               <p><?php echo Html::entities_deep($summaryDisabledMsg); ?></p>
            </div>
         </div>
      <?php endif; ?>
   </section>

   <!-- Análise de Sentimento (AI) -->
   <section class="aiassist-panel aiassist-panel--sentiment">
      <header class="aiassist-panel__header">
         <div class="aiassist-panel__title">
            <span class="aiassist-panel__eyebrow">
               <i class="ti ti-robot"></i>
               AI Assist
            </span>
            <h3><?php echo __('Análise de Sentimento', 'nextool'); ?></h3>
         </div>

         <?php if ($sentimentLabel): ?>
            <div class="aiassist-panel__status">
               <span class="aiassist-pill" data-tone="<?php echo Html::entities_deep($sentimentSlug); ?>">
                  <?php echo Html::entities_deep($sentimentLabel); ?>
               </span>
               <?php if ($sentimentScore !== null): ?>
                  <span class="aiassist-score"><?php echo number_format($sentimentScore, 1, ',', '.'); ?></span>
               <?php endif; ?>
            </div>
         <?php else: ?>
            <div class="aiassist-panel__status aiassist-panel__status--muted">
               <?php echo __('Nenhuma análise registrada ainda.', 'nextool'); ?>
            </div>
         <?php endif; ?>
      </header>

      <p class="aiassist-panel__intro">
         <?php echo __('A análise considera exclusivamente o título e a descrição inicial registrados na abertura do chamado.', 'nextool'); ?>
      </p>

      <div id="aiassist-result" class="aiassist-result">
         <?php if ($sentimentLabel): ?>
            <div class="aiassist-result__content">
               <?php if ($sentimentRationale !== ''): ?>
                  <p class="aiassist-result__rationale"><?php echo Html::entities_deep($sentimentRationale); ?></p>
               <?php else: ?>
                  <p class="aiassist-result__rationale">
                     <?php
                        $summary = sprintf(__('Sentimento detectado: %s', 'nextool'), Html::entities_deep($sentimentLabel));
                        if ($sentimentScore !== null) {
                           $summary .= ' (' . number_format($sentimentScore, 1, ',', '.') . ')';
                        }
                        echo $summary;
                     ?>
                  </p>
               <?php endif; ?>

               <?php if ($lastSentimentAt): ?>
               <div class="aiassist-meta">
                  <i class="ti ti-clock"></i>
                  <span><?php printf(__('Última análise: %s', 'nextool'), Html::convDateTime($lastSentimentAt)); ?></span>
               </div>
               <?php endif; ?>
            </div>
         <?php else: ?>
            <div class="aiassist-result__empty">
               <i class="ti ti-mood-empty"></i>
               <p><?php echo __('Ainda não analisamos este chamado.', 'nextool'); ?></p>
               <small><?php echo __('Solicite uma análise para visualizar sentimento e urgência.', 'nextool'); ?></small>
            </div>
         <?php endif; ?>
      </div>

      <div class="aiassist-context">
         <div class="aiassist-context__block">
            <label><?php echo __('Título do chamado', 'nextool'); ?></label>
            <p><?php echo $ticketTitle !== '' ? Html::entities_deep($ticketTitle) : __('Não informado', 'nextool'); ?></p>
         </div>
         <div class="aiassist-context__block">
            <label><?php echo __('Descrição inicial', 'nextool'); ?></label>
            <?php if ($descriptionDisplay !== ''): ?>
               <div class="aiassist-context__description"><?php echo $descriptionDisplay; ?></div>
            <?php else: ?>
               <p class="aiassist-context__placeholder"><?php echo __('Nenhuma descrição registrada.', 'nextool'); ?></p>
            <?php endif; ?>
         </div>
      </div>

      <?php if ($sentimentEnabled): ?>
         <div class="aiassist-actions">
            <button type="button"
                    class="aiassist-btn"
                    data-aiassist-action="sentiment"
                    <?php echo $sentimentDisabledMsg ? 'data-disabled-message="' . Html::entities_deep($sentimentDisabledMsg) . '"' : ''; ?>>
               <i class="ti ti-heart-rate-monitor"></i>
               <span><?php echo $sentimentLabel ? __('Reanalisar sentimento', 'nextool') : __('Analisar sentimento', 'nextool'); ?></span>
            </button>
            <div id="aiassist-feedback" class="aiassist-feedback is-hidden" aria-live="polite"></div>
         </div>
      <?php else: ?>
         <div class="aiassist-callout">
            <i class="ti ti-lock"></i>
            <div>
               <strong><?php echo __('Funcionalidade desativada', 'nextool'); ?></strong>
               <p><?php echo Html::entities_deep($sentimentDisabledMsg); ?></p>
            </div>
         </div>
      <?php endif; ?>
   </section>
</div>

<style>
:root {
   --aiassist-surface: #ffffff;
   --aiassist-surface-alt: #f8fafc;
   --aiassist-border: #e2e8f0;
   --aiassist-text: #0f172a;
   --aiassist-text-muted: #475569;
   --aiassist-primary: #7c3aed;
   --aiassist-success: #10b981;
   --aiassist-warning: #f59e0b;
   --aiassist-danger: #dc2626;
}

.aiassist-simple-tab {
   padding: 1.25rem;
   background: var(--aiassist-surface-alt);
}

.aiassist-panel {
   max-width: 980px;
   margin: 0 auto;
   background: var(--aiassist-surface);
   border: 1px solid var(--aiassist-border);
   border-radius: 16px;
   box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
   padding: 1.75rem;
}

.aiassist-panel__header {
   display: flex;
   justify-content: space-between;
   gap: 1rem;
   flex-wrap: wrap;
   align-items: center;
}

.aiassist-panel__title h3 {
   margin: 0.35rem 0 0;
   font-size: 1.35rem;
   color: var(--aiassist-text);
}

.aiassist-panel__eyebrow {
   font-size: 0.85rem;
   letter-spacing: .08em;
   text-transform: uppercase;
   color: var(--aiassist-text-muted);
   display: inline-flex;
   gap: 0.35rem;
   align-items: center;
}

.aiassist-panel__status {
   display: inline-flex;
   align-items: center;
   gap: 0.75rem;
   font-weight: 600;
}

.aiassist-panel__status--muted {
   color: var(--aiassist-text-muted);
   font-size: 0.95rem;
}

.aiassist-panel__intro {
   margin: 1rem 0 1.5rem;
   color: var(--aiassist-text-muted);
   font-size: 0.95rem;
}

.aiassist-context {
   border: 1px solid var(--aiassist-border);
   border-radius: 14px;
   padding: 1.25rem;
   background: #fbfbff;
   display: grid;
   gap: 1.25rem;
}

.aiassist-context__block label {
   font-size: 0.8rem;
   text-transform: uppercase;
   color: var(--aiassist-text-muted);
   letter-spacing: 0.05em;
   display: block;
   margin-bottom: 0.35rem;
}

.aiassist-context__block p {
   margin: 0;
   font-size: 1rem;
   color: var(--aiassist-text);
}

.aiassist-context__description {
   padding: 0.75rem 0;
   white-space: pre-wrap;
   color: var(--aiassist-text);
}

.aiassist-context__placeholder {
   color: var(--aiassist-text-muted);
   font-style: italic;
}

.aiassist-result {
   margin-top: 1.5rem;
   border: 1px dashed var(--aiassist-border);
   border-radius: 14px;
   padding: 1.25rem;
   background: #fefefe;
}

.aiassist-result__content {
   display: flex;
   flex-direction: column;
   gap: 1rem;
}

.aiassist-summary-result__content {
   white-space: pre-wrap;
   word-break: break-word;
   font-family: inherit;
   font-size: 0.95rem;
   color: var(--aiassist-text-muted);
   margin: 0;
}

.aiassist-result__rationale {
   margin: 0;
   color: var(--aiassist-text-muted);
   font-style: italic;
}

.aiassist-pill {
   display: inline-flex;
   align-items: center;
   padding: 0.35rem 0.85rem;
   border-radius: 999px;
   font-size: 0.85rem;
   font-weight: 600;
   text-transform: uppercase;
   letter-spacing: 0.05em;
}

.aiassist-pill[data-tone="positivo"] {
   background: rgba(34, 197, 94, 0.15);
   color: #065f46;
}

.aiassist-pill[data-tone="neutro"] {
   background: rgba(148, 163, 184, 0.2);
   color: #0f172a;
}

.aiassist-pill[data-tone="negativo"] {
   background: rgba(249, 115, 22, 0.2);
   color: #9a3412;
}

.aiassist-pill[data-tone="critico"],
.aiassist-pill[data-tone="critica"] {
   background: rgba(248, 113, 113, 0.25);
   color: #7f1d1d;
}

.aiassist-score {
   font-size: 1.4rem;
   font-weight: 700;
   color: var(--aiassist-text);
}

.aiassist-meta {
   display: inline-flex;
   align-items: center;
   gap: 0.5rem;
   background: var(--aiassist-surface-alt);
   border-radius: 999px;
   padding: 0.35rem 0.85rem;
   font-size: 0.85rem;
   color: var(--aiassist-text-muted);
}

.aiassist-result__empty {
   text-align: center;
   color: var(--aiassist-text-muted);
}

.aiassist-result__empty i {
   font-size: 2.5rem;
   margin-bottom: 0.5rem;
}

.aiassist-actions {
   margin-top: 1.5rem;
   display: flex;
   flex-direction: column;
   gap: 0.75rem;
}

.aiassist-btn {
   border: none;
   border-radius: 999px;
   padding: 0.9rem 1.75rem;
   font-size: 1rem;
   font-weight: 600;
   background: linear-gradient(120deg, #7c3aed, #a855f7);
   color: #fff;
   display: inline-flex;
   gap: 0.65rem;
   align-items: center;
   justify-content: center;
   cursor: pointer;
   transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.aiassist-btn:hover {
   box-shadow: 0 15px 25px rgba(99, 102, 241, 0.35);
   transform: translateY(-1px);
}

.aiassist-btn:disabled {
   opacity: 0.6;
   cursor: not-allowed;
   box-shadow: none;
   transform: none;
}

.aiassist-feedback {
   font-size: 0.92rem;
   padding: 0.75rem 1rem;
   border-radius: 12px;
   border: 1px solid transparent;
}

.aiassist-feedback.is-hidden {
   display: none;
}

.aiassist-feedback[data-state="success"] {
   background: rgba(16, 185, 129, 0.1);
   border-color: rgba(16, 185, 129, 0.4);
   color: #065f46;
}

.aiassist-feedback[data-state="error"] {
   background: rgba(248, 113, 113, 0.1);
   border-color: rgba(248, 113, 113, 0.4);
   color: #7f1d1d;
}

.aiassist-feedback[data-state="warning"] {
   background: rgba(251, 191, 36, 0.15);
   border-color: rgba(249, 115, 22, 0.4);
   color: #92400e;
}

.aiassist-callout {
   margin-top: 1.25rem;
   border: 1px solid var(--aiassist-border);
   border-radius: 12px;
   padding: 1rem;
   display: flex;
   gap: 0.75rem;
   align-items: flex-start;
   background: #fff7ed;
   color: #78350f;
}

@media (max-width: 640px) {
   .aiassist-panel {
      padding: 1.25rem;
   }

   .aiassist-panel__header {
      flex-direction: column;
      align-items: flex-start;
   }

   .aiassist-pill {
      font-size: 0.75rem;
   }
}
</style>

<script>
(function() {
   'use strict';

   var tab = document.getElementById('aiassist-tab');
   if (!tab) {
      return;
   }

   var endpoint = <?php echo json_encode(
      Plugin::getWebDir('nextool') . '/ajax/module_ajax.php?module=aiassist&file=aiassist.action.php',
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES
   ); ?>;
   var ticketId = <?php echo (int)$ticketId; ?>;
   var csrfField = tab.querySelector('input[name="_glpi_csrf_token"]');
   var sentimentButton = tab.querySelector('[data-aiassist-action="sentiment"]');
   var sentimentResult = document.getElementById('aiassist-result');
   var sentimentFeedback = document.getElementById('aiassist-feedback');

   var summaryButton = tab.querySelector('[data-aiassist-action="summary"]');
   var summaryResult = document.getElementById('aiassist-summary-result');
   var summaryFeedback = document.getElementById('aiassist-summary-feedback');

   var messages = <?php echo json_encode([
      'processing' => __('Processando...', 'nextool'),
      'summary'    => [
         'success'      => __('Resumo concluído com sucesso.', 'nextool'),
         'genericError' => __('Não foi possível gerar o resumo.', 'nextool'),
         'unexpectedError' => __('Erro inesperado. Tente novamente em instantes.', 'nextool'),
         'emptyTitle'   => __('Ainda não existe um resumo consolidado para este chamado.', 'nextool'),
         'emptyHint'    => __('Use o botão abaixo ou o atalho no formulário para gerar o primeiro resumo.', 'nextool'),
         'lastPrefix'   => __('Último resumo:', 'nextool'),
         'updateLabel'  => __('Atualizar resumo', 'nextool'),
      ],
      'sentiment' => [
         'emptyTitle'      => __('Ainda não analisamos este chamado.', 'nextool'),
         'emptyHint'       => __('Solicite uma análise para visualizar sentimento e urgência.', 'nextool'),
         'label'           => __('Sentimento detectado', 'nextool'),
         'lastAnalysis'    => __('Última análise:', 'nextool'),
         'success'         => __('Análise concluída com sucesso.', 'nextool'),
         'genericError'    => __('Não foi possível concluir a análise.', 'nextool'),
         'unexpectedError' => __('Erro inesperado. Tente novamente em instantes.', 'nextool'),
      ],
   ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;

   if (sentimentButton) {
      sentimentButton.addEventListener('click', function() {
         var blocked = sentimentButton.getAttribute('data-disabled-message');
         if (blocked) {
            showFeedback(sentimentFeedback, 'warning', blocked);
            return;
         }

         runAnalysis('sentiment');
      });
   }

   if (summaryButton) {
      summaryButton.addEventListener('click', function() {
         var blocked = summaryButton.getAttribute('data-disabled-message');
         if (blocked) {
            showFeedback(summaryFeedback, 'warning', blocked);
            return;
         }

         runAnalysis('summary');
      });
   }

   function runAnalysis(action) {
      var csrfToken = csrfField ? csrfField.value : '';
      var params = new URLSearchParams();
      params.append('_glpi_csrf_token', csrfToken);
      params.append('tickets_id', ticketId);
      params.append('action', action);

      var isSentiment = action === 'sentiment';
      var button = isSentiment ? sentimentButton : summaryButton;
      var feedback = isSentiment ? sentimentFeedback : summaryFeedback;

      setLoadingState(button, true);

      fetch(endpoint, {
         method: 'POST',
         headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': csrfToken,
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
         },
         credentials: 'same-origin',
         body: params.toString()
      })
      .then(function(response) {
         return response.text().then(function(text) {
            var payload = null;
            try {
               payload = text ? JSON.parse(text) : null;
            } catch (error) {
               payload = null;
            }
            return { ok: response.ok, payload: payload };
         });
      })
      .then(function(result) {
         var payload = result.payload || null;

         if (payload && payload.next_csrf_token && csrfField) {
            csrfField.value = payload.next_csrf_token;
         }

         if (!result.ok || !payload || !payload.success) {
            var msg = (payload && payload.message)
               ? payload.message
               : (isSentiment ? messages.sentiment.genericError : messages.summary.genericError);
            showFeedback(feedback, 'error', msg);
            if (payload && payload.block_reason && button) {
               button.setAttribute('data-disabled-message', payload.block_reason);
            }
            return;
         }

         if (isSentiment) {
            updateSentimentResult(payload.data || {});
            showFeedback(feedback, 'success', payload.message || messages.sentiment.success);
            if (button) {
               button.removeAttribute('data-disabled-message');
            }
         } else {
            updateSummaryResult(payload.data || {});
            showFeedback(feedback, 'success', payload.message || messages.summary.success);
            if (button) {
               button.removeAttribute('data-disabled-message');
            }
         }
      })
      .catch(function() {
         var msg = isSentiment ? messages.sentiment.unexpectedError : messages.summary.unexpectedError;
         showFeedback(feedback, 'error', msg);
      })
      .finally(function() {
         setLoadingState(button, false);
      });
   }

   function updateSummaryResult(data) {
      if (!summaryResult) {
         return;
      }

      if (!data || !data.summary_text) {
         summaryResult.innerHTML =
            '<div class="aiassist-summary-result__empty">' +
               '<i class="ti ti-notebook"></i>' +
               '<p>' + messages.summary.emptyTitle + '</p>' +
               '<small>' + messages.summary.emptyHint + '</small>' +
            '</div>';
         return;
      }

      var raw = data.summary_text || '';
      var safe = escapeHtml(raw);
      // Quebras de linha simples
      safe = safe.replace(/\n/g, '<br>');
      // Remove marcadores ** de markdown para evitar problemas de regex na renderização
      safe = safe.split('**').join('');
      summaryResult.innerHTML = '<pre class="aiassist-summary-result__content">' + safe + '</pre>';

      var status = document.getElementById('aiassist-summary-status');
      if (status && data.updated_at) {
         status.classList.remove('aiassist-panel__status--muted');
         status.innerHTML =
            '<i class="ti ti-clock"></i><span>' +
            messages.summary.lastPrefix + ' ' + escapeHtml(data.updatedAt || data.updated_at) +
            '</span>';
      }

      if (summaryButton) {
         var span = summaryButton.querySelector('span');
         if (span) {
            span.textContent = messages.summary.updateLabel;
         }
      }
   }

   function updateSentimentResult(data) {
      if (!sentimentResult) {
         return;
      }

      if (!data || !data.sentiment_label) {
         sentimentResult.innerHTML =
            '<div class="aiassist-result__empty">' +
               '<i class="ti ti-mood-empty"></i>' +
               '<p>' + messages.sentiment.emptyTitle + '</p>' +
               '<small>' + messages.sentiment.emptyHint + '</small>' +
            '</div>';
         return;
      }

      var label = escapeHtml(data.sentiment_label);
      var score = formatScore(data.sentiment_score);
      var rationale = data.rationale
         ? '<p class="aiassist-result__rationale">' + escapeHtml(data.rationale) + '</p>'
         : '';
      var updatedAt = data.updated_at ? escapeHtml(data.updated_at) : '';

      var body = rationale;
      if (!body) {
         var base = messages.sentiment.label + ' ' + label;
         if (score) {
            base += ' (' + score + ')';
         }
         body = '<p class="aiassist-result__rationale">' + base + '</p>';
      }

      var meta = '';
      if (updatedAt) {
         meta =
            '<div class="aiassist-meta">' +
               '<i class="ti ti-clock"></i>' +
               '<span>' + messages.sentiment.lastAnalysis + ' ' + updatedAt + '</span>' +
            '</div>';
      }

      sentimentResult.innerHTML =
         '<div class="aiassist-result__content">' +
            body +
            meta +
         '</div>';
   }

   function showFeedback(feedbackEl, type, message) {
      if (!feedbackEl || !message) {
         return;
      }

      feedbackEl.dataset.state = type;
      feedbackEl.textContent = message;
      feedbackEl.classList.remove('is-hidden');
   }

   function setLoadingState(button, isLoading) {
      if (!button) {
         return;
      }

      if (isLoading) {
         button.disabled = true;
         button.dataset.loading = 'true';
         var span = button.querySelector('span');
         if (span) {
            button.dataset.originalText = span.textContent;
            span.textContent = messages.processing;
         }
      } else {
         button.disabled = false;
         button.dataset.loading = 'false';
         var span = button.querySelector('span');
         if (span && button.dataset.originalText) {
            span.textContent = button.dataset.originalText;
         }
         delete button.dataset.originalText;
      }
   }

   function escapeHtml(value) {
      var div = document.createElement('div');
      div.textContent = value === undefined || value === null ? '' : String(value);
      return div.innerHTML;
   }

   function formatScore(value) {
      if (value === undefined || value === null || value === '') {
         return '';
      }

      var number = Number(value);
      if (isNaN(number)) {
         return '';
      }

      return new Intl.NumberFormat('pt-BR', {
         minimumFractionDigits: 1,
         maximumFractionDigits: 1
      }).format(number);
   }

})();
</script>

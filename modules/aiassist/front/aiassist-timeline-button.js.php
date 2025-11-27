<?php
if (!defined('GLPI_ROOT')) {
   include('../../../../../inc/includes.php');
}

// Garante que cabeçalho correto seja enviado
header('Content-Type: application/javascript; charset=UTF-8');

$endpoint = Plugin::getWebDir('nextool') . '/ajax/module_ajax.php?module=aiassist&file=aiassist.action.php';

$messages = [
   'label'            => __('Resumo (AI)', 'nextool'),
   'title'            => __('Resumo do Chamado', 'nextool'),
   'close'            => __('Fechar', 'nextool'),
   'processing'       => __('Gerando resumo...', 'nextool'),
   'generic_error'    => __('Não foi possível gerar o resumo.', 'nextool'),
   'unexpected_error' => __('Erro inesperado. Tente novamente em instantes.', 'nextool'),
];

$endpointJson = json_encode($endpoint, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$messagesJson = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

?>
(function() {
   'use strict';

   var config = {
      endpoint: <?php echo $endpointJson; ?>,
      messages: <?php echo $messagesJson; ?>
   };

   function getTicketId() {
      // Primeiro tenta pela URL (?id=XX)
      try {
         var params = new URLSearchParams(window.location.search);
         var idParam = params.get('id');
         var idNum = parseInt(idParam, 10);
         if (!isNaN(idNum) && idNum > 0) {
            return idNum;
         }
      } catch (e) {
         // Ignora erros de URLSearchParams em browsers muito antigos
      }

      // Fallback: procura em campos do formulário
      var form = document.querySelector('#itil-form') || document.querySelector('form[name="ticketform"]');
      if (form) {
         var idInput = form.querySelector('input[name="id"], input[name="tickets_id"], input[name="tickets_id_display"]');
         if (idInput) {
            var value = parseInt(idInput.value, 10);
            if (!isNaN(value) && value > 0) {
               return value;
            }
         }
      }

      return null;
   }

   function findActionsContainer() {
      var container = document.querySelector('.timeline-buttons');
      if (!container) {
         container = document.querySelector('.answer-actions') || document.querySelector('.ticket-actions');
      }
      return container;
   }

   function ensureButton(container, ticketId) {
      if (!container || !ticketId) {
         return;
      }

      // Evita duplicar o botão
      if (container.querySelector('.action-summary')) {
         return;
      }

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ms-2 btn btn-primary answer-action action-summary';
      btn.innerHTML = '<i class="ti ti-file-text me-1"></i><span>' + config.messages.label + '</span>';

      btn.addEventListener('click', function() {
         runSummary(btn, ticketId);
      });

      // Tenta adicionar dentro do bloco de ações principais para manter alinhamento
      var mainActions = container.querySelector('.main-actions');
      if (mainActions) {
         mainActions.appendChild(btn);
      } else {
         container.appendChild(btn);
      }
   }

   function runSummary(button, ticketId) {
      try {
         if (!window.fetch || !window.URLSearchParams) {
            notifyError(config.messages.generic_error);
            return;
         }

         var form = document.querySelector('#itil-form') || document.querySelector('form[name="ticketform"]');
         if (!form) {
            notifyError(config.messages.generic_error);
            return;
         }

         var csrfInput = form.querySelector('input[name="_glpi_csrf_token"]');
         var csrfToken = csrfInput ? csrfInput.value : '';
         if (!csrfToken) {
            notifyError(config.messages.generic_error);
            return;
         }

         if (button.dataset.loading === '1') {
            return;
         }

         var originalHtml = button.dataset.originalHtml || button.innerHTML;
         button.dataset.originalHtml = originalHtml;
         button.dataset.loading = '1';
         button.disabled = true;
         button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + config.messages.processing;

         var params = new URLSearchParams();
         params.append('_glpi_csrf_token', csrfToken);
         params.append('tickets_id', ticketId);
         params.append('action', 'summary');

         fetch(config.endpoint, {
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
               } catch (e) {
                  payload = null;
               }
               return { ok: response.ok, payload: payload };
            });
         })
         .then(function(result) {
            var payload = result.payload || {};
            var data = payload.data || null;

            if (payload.next_csrf_token && csrfInput) {
               csrfInput.value = payload.next_csrf_token;
            }

            if (!result.ok || !payload.success) {
               var msg = payload.message || config.messages.generic_error;
               notifyError(msg);
               return;
            }

            var summaryText = '';
            if (typeof data === 'string') {
               summaryText = data;
            } else if (data && typeof data.summary_text === 'string') {
               summaryText = data.summary_text;
            }

            showSummaryModal(summaryText);
         })
         .catch(function() {
            notifyError(config.messages.unexpected_error);
         })
         .finally(function() {
            button.disabled = false;
            button.dataset.loading = '0';
            button.innerHTML = button.dataset.originalHtml || originalHtml;
         });

      } catch (e) {
         notifyError(config.messages.unexpected_error);
      }
   }

   function notifyError(message) {
      if (typeof glpi_toast_error === 'function') {
         glpi_toast_error(message);
      } else {
         console.error('[AI Assist] ' + message);
         alert(message);
      }
   }

   function showSummaryModal(summaryText) {
      var modalId = 'aiassist-summary-modal';
      var overlayId = 'aiassist-summary-modal-overlay';

      var overlay = document.getElementById(overlayId);
      var modal = document.getElementById(modalId);

      if (!overlay) {
         overlay = document.createElement('div');
         overlay.id = overlayId;
         overlay.style.position = 'fixed';
         overlay.style.inset = '0';
         overlay.style.background = 'rgba(15,23,42,0.45)';
         overlay.style.zIndex = '1055';
         overlay.style.display = 'flex';
         overlay.style.alignItems = 'center';
         overlay.style.justifyContent = 'center';
         document.body.appendChild(overlay);

         overlay.addEventListener('click', function(event) {
            if (event.target === overlay) {
               overlay.style.display = 'none';
            }
         });
      }

      if (!modal) {
         modal = document.createElement('div');
         modal.id = modalId;
         modal.style.maxWidth = '680px';
         modal.style.width = '100%';
         modal.style.background = '#ffffff';
         modal.style.borderRadius = '16px';
         modal.style.boxShadow = '0 20px 40px rgba(15,23,42,0.35)';
         modal.style.padding = '1.5rem 1.75rem';
         modal.style.position = 'relative';

         modal.innerHTML =
            '<div class="d-flex justify-content-between align-items-start mb-2">' +
               '<div>' +
                  '<div class="text-muted text-uppercase" style="font-size:0.75rem;letter-spacing:.08em;">AI Assist</div>' +
                  '<h5 class="mb-0">' + config.messages.title + '</h5>' +
               '</div>' +
               '<button type="button" class="btn-close" aria-label="' + config.messages.close + '"></button>' +
            '</div>' +
            '<div class="mb-3" style="max-height:380px;overflow:auto;">' +
               '<pre id="aiassist-summary-modal-content" style="white-space:pre-wrap;word-break:break-word;font-size:0.92rem;line-height:1.5;margin:0;"></pre>' +
            '</div>' +
            '<div class="d-flex justify-content-end gap-2">' +
               '<button type="button" class="btn btn-light btn-sm" data-role="close-summary-modal">' + config.messages.close + '</button>' +
            '</div>';

         overlay.appendChild(modal);

         var closeButtons = modal.querySelectorAll('.btn-close, [data-role="close-summary-modal"]');
         closeButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
               overlay.style.display = 'none';
            });
         });
      }

      var contentEl = modal.querySelector('#aiassist-summary-modal-content');
      if (contentEl) {
         var raw = summaryText || '';

         // Escapa HTML básico
         var safe = raw
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

         // Converte quebras de linha em <br> e remove marcadores simples ** de markdown
         safe = safe.replace(/\n/g, '<br>');
         safe = safe.split('**').join('');

         contentEl.innerHTML = safe;
      }

      overlay.style.display = 'flex';

      var closeBtn = modal.querySelector('.btn-close');
      if (closeBtn) {
         closeBtn.focus();
      }
   }

   function bootstrap() {
      // Só tenta injetar o botão em páginas de chamado (ticket.form)
      var ticketId = getTicketId();
      if (!ticketId) {
         return;
      }

      var container = findActionsContainer();
      if (container) {
         ensureButton(container, ticketId);
         return;
      }

      var attempts = 0;
      var watcher = setInterval(function() {
         attempts++;
         var host = findActionsContainer();
         if (host || attempts > 40) {
            clearInterval(watcher);
            if (host) {
               ensureButton(host, ticketId);
            }
         }
      }, 200);
   }

   if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bootstrap);
   } else {
      bootstrap();
   }
})();



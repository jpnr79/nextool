<?php
if (!defined('GLPI_ROOT')) {
   include('../../../../../inc/includes.php');
} else if (!class_exists('Plugin')) {
   if (!defined('GLPI_AJAX')) define('GLPI_AJAX', true);
   include_once(GLPI_ROOT . '/inc/includes.php');
}

header('Content-Type: application/javascript; charset=UTF-8');

// Verifica permissão de visualização do módulo
if (file_exists(GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php')) {
   require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
   
   if (!PluginNextoolPermissionManager::canViewModule('aiassist')) {
      // Retorna JavaScript vazio se não tem permissão
      echo '// AI Assist: Sem permissão de visualização';
      exit;
   }
}

// Consulta configurações do módulo
$summaryEnabled = true;
$replyEnabled   = true;

if (file_exists(GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php')) {
   require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
   require_once GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';
   
   $manager = PluginNextoolModuleManager::getInstance();
   $module = $manager->getModule('aiassist');
   if ($module) {
      $settings = $module->getSettings();
      // Garante conversão para booleano/inteiro correto para o JS
      $summaryEnabled = !empty($settings['feature_summary_enabled']);
      $replyEnabled   = !empty($settings['feature_reply_enabled']);
   }
}

$endpoint = Plugin::getWebDir('nextool') . '/ajax/module_ajax.php?module=aiassist&file=aiassist.action.php';

$messages = [
   'summary_label'    => __('Resumo (AI)', 'nextool'),
   'reply_label'      => __('Sugerir resposta', 'nextool'),
   'summary_title'    => __('Resumo do Chamado', 'nextool'),
   'reply_title'      => __('Sugestão de Resposta', 'nextool'),
   'close'            => __('Fechar', 'nextool'),
   'copy'             => __('Copiar', 'nextool'),
   'copied'           => __('Copiado!', 'nextool'),
   'insert'           => __('Inserir no editor', 'nextool'),
   'inserted'         => __('Inserido!', 'nextool'),
   'editor_error'     => __('Não foi possível encontrar o editor de texto para inserir o conteúdo.', 'nextool'),
   'processing'       => __('Processando...', 'nextool'),
   'generic_error'    => __('Ocorreu um erro ao processar a solicitação.', 'nextool'),
   'unexpected_error' => __('Erro inesperado. Tente novamente em instantes.', 'nextool'),
];

$configData = [
   'endpoint' => $endpoint,
   'messages' => $messages,
   'features' => [
      'summary' => $summaryEnabled,
      'reply'   => $replyEnabled
   ]
];

$configJson = json_encode($configData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

?>
(function() {
   'use strict';

   var config = <?php echo $configJson; ?>;

   function getTicketId() {
      // 1. Validação de Contexto Global: Garante que estamos em uma página de Ticket
      var isTicketPage = window.location.pathname.indexOf('front/ticket.form.php') > -1;
      var hasTicketInput = document.querySelector('input[name="itemtype"][value="Ticket"]');
      
      if (!isTicketPage && !hasTicketInput) {
         return null;
      }

      // 2. Extração do ID
      try {
         var params = new URLSearchParams(window.location.search);
         var idParam = params.get('id');
         var idNum = parseInt(idParam, 10);
         if (!isNaN(idNum) && idNum > 0) return idNum;
      } catch (e) {}

      var form = document.querySelector('#itil-form') || document.querySelector('form[name="ticketform"]');
      if (form) {
         var idInput = form.querySelector('input[name="id"], input[name="tickets_id"], input[name="tickets_id_display"]');
         if (idInput) {
            var value = parseInt(idInput.value, 10);
            if (!isNaN(value) && value > 0) return value;
         }
      }
      return null;
   }

   // Verifica se o formulário pai pertence EXPLICITAMENTE a um Ticket
   function isTicketForm(element) {
      var form = element.closest('form');
      if (!form) return false;
      
      // Procura input hidden com name="itemtype" e value="Ticket"
      var inputType = form.querySelector('input[name="itemtype"]');
      if (inputType && inputType.value === 'Ticket') {
         return true;
      }
      return false;
   }

   // Verifica especificamente se o elemento pertence ao formulário de documentos/anexos
   function isDocumentForm(element) {
      // 1. Verifica wrapper específico do formulário de documento
      if (element.closest('.document_item')) {
         return true;
      }

      var form = element.closest('form');
      if (!form) return false;

      // ATENÇÃO: NÃO verificar form.name === 'asset_form' pois Solução também usa esse nome.
      
      // 2. Verifica pela URL de action (document.form.php)
      var action = form.getAttribute('action');
      if (action && action.indexOf('document.form.php') > -1) {
         return true;
      }

      return false;
   }

   function findAllRelevantContainers() {
      var containers = [];

      // 1. Footer de Adicionar (Nova resposta, Solução)
      // Expandido para incluir #new-ITILSolution-block explicitamente
      var addContainers = document.querySelectorAll('#new-itilobject-form, #new-ITILSolution-block');
      
      addContainers.forEach(function(container) {
         if (!container) return;
         
         var addBtns = container.querySelectorAll('button[name="add"]');
         addBtns.forEach(function(btn) {
            // Verifica apenas se NÃO é formulário de documento; o contexto de Ticket já foi garantido em getTicketId()
            if (!isDocumentForm(btn)) {
               var footer = btn.closest('.card-footer');
               if (footer) containers.push({ el: footer, type: 'footer_add' });
            }
         });
      });

      // 2. Footer de Editar (Atualizar resposta existente)
      // Busca apenas dentro da timeline
      var timeline = document.querySelector('.timeline-view') || document.body;
      var updateBtns = timeline.querySelectorAll('.timeline-item button[name="update"]');
      
      updateBtns.forEach(function(btn) {
         // Verifica apenas se NÃO é formulário de documento; o contexto de Ticket já foi garantido em getTicketId()
         if (!isDocumentForm(btn)) {
             var footer = btn.closest('.card-footer');
             if (footer) containers.push({ el: footer, type: 'footer_update' });
         }
      });

      // 3. Ações da Timeline (Botões no topo do chamado)
      var timelineActions = document.querySelector('.timeline-buttons') || 
                            document.querySelector('.answer-actions') || 
                            document.querySelector('.ticket-actions');
      if (timelineActions) {
         containers.push({ el: timelineActions, type: 'timeline_actions' });
      }

      return containers;
   }

   function ensureButtons(containerObj, ticketId) {
      var container = containerObj.el;
      var type = containerObj.type;

      if (!container || !ticketId) return;

      // --- LÓGICA DE LOCALIZAÇÃO ESTRITA ---

      // Resumo: APENAS na barra de ações gerais (timeline_actions)
      var showSummary = config.features.summary && type === 'timeline_actions';
      
      // Sugerir Resposta: APENAS nos rodapés de formulários (footer_add OU footer_update)
      // NUNCA na timeline_actions
      var showReply = config.features.reply && (type === 'footer_add' || type === 'footer_update');

      // Verifica se é um formulário de Solução para alinhar à direita
      var isSolutionForm = false;
      var parentForm = container.closest('form');
      if (parentForm) {
         var action = parentForm.getAttribute('action');
         if (action && action.indexOf('itilsolution.form.php') > -1) {
            isSolutionForm = true;
         }
      }

      // Injeção do botão Resumo
      if (showSummary && !container.querySelector('.action-summary')) {
         var btnSummary = createButton('summary', config.messages.summary_label, 'ti-file-text', 'btn-outline-secondary');
         btnSummary.addEventListener('click', function() { runAction(btnSummary, ticketId, 'summary'); });
         injectButton(container, btnSummary);
      }

      // Injeção do botão Sugerir Resposta
      if (showReply && !container.querySelector('.action-reply')) {
         var btnReply = createButton('reply', config.messages.reply_label, 'ti-message-circle', 'btn-primary');
         // Mantém o mesmo padrão visual dos demais botões (btn + ms-2),
         // deixando o Bootstrap cuidar do alinhamento lado a lado.
         btnReply.addEventListener('click', function() { runAction(btnReply, ticketId, 'reply'); });
         injectButton(container, btnReply);
      }
   }

   function createButton(type, label, iconClass, btnClass) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn ' + btnClass + ' answer-action action-' + type + ' ms-2';
      btn.innerHTML = '<i class="ti ' + iconClass + ' me-1"></i><span>' + label + '</span>';
      return btn;
   }

   function injectButton(container, btn) {
      // Caso 1: GLPI já tem um wrapper de ações principais
      var mainActions = container.querySelector('.main-actions');
      if (mainActions) {
         mainActions.appendChild(btn);
         return;
      }

      // Caso 2: Footer simples com botão "add" (Responder, etc.)
      var primaryAddBtn = container.querySelector('button[name="add"]');
      if (primaryAddBtn && primaryAddBtn.parentElement) {
         primaryAddBtn.parentElement.insertBefore(btn, primaryAddBtn.nextSibling);
         return;
      }

      // Fallback: adiciona no final do footer
      container.appendChild(btn);
   }

   function runAction(button, ticketId, actionType) {
      try {
         var targetEditorId = null;
         var parentForm = button.closest('form');
         if (parentForm) {
            var targetTextarea = parentForm.querySelector('textarea[name="content"]');
            if (targetTextarea) {
               targetEditorId = targetTextarea.id;
            }
         }

         if (!window.fetch || !window.URLSearchParams) {
            notifyError(config.messages.generic_error);
            return;
         }

         var form = document.querySelector('#itil-form') || document.querySelector('form[name="ticketform"]');
         var csrfInput = form ? form.querySelector('input[name="_glpi_csrf_token"]') : null;
         var csrfToken = csrfInput ? csrfInput.value : '';
         
         if (!csrfToken) {
            var anyCsrf = document.querySelector('input[name="_glpi_csrf_token"]');
            if (anyCsrf) csrfToken = anyCsrf.value;
            else {
               notifyError(config.messages.generic_error); 
               return;
            }
         }

         if (button.dataset.loading === '1') return;

         var originalHtml = button.dataset.originalHtml || button.innerHTML;
         button.dataset.originalHtml = originalHtml;
         button.dataset.loading = '1';
         button.disabled = true;
         button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + config.messages.processing;

         var params = new URLSearchParams();
         params.append('_glpi_csrf_token', csrfToken);
         params.append('tickets_id', ticketId);
         params.append('action', actionType);
         
         // Se botão tem flag forceNew, envia force=1
         if (button.dataset.forceNew === '1') {
            params.append('force', '1');
         }
 
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
               try { return { ok: response.ok, payload: text ? JSON.parse(text) : null }; } 
               catch (e) { return { ok: false, payload: null }; }
            });
         })
         .then(function(result) {
            var payload = result.payload || {};
            var data = payload.data || null;

            if (payload.next_csrf_token && csrfInput) csrfInput.value = payload.next_csrf_token;

            if (payload.next_csrf_token) {
                var allCsrfInputs = document.querySelectorAll('input[name="_glpi_csrf_token"]');
                allCsrfInputs.forEach(function(inp) { inp.value = payload.next_csrf_token; });
            }

            if (!result.ok || !payload.success) {
               var msg = payload.message || config.messages.generic_error;
               notifyError(msg);
               return;
            }

            if (actionType === 'summary') {
               // Para summary: usar summary_html (formatado) para exibir no modal
               var displayText = payload.summary_html || payload.summary_text || payload.data || '';
               var plainText = payload.summary_text || payload.data || '';
               var fromCache = payload.from_cache || false;
               var cachedAt = payload.cached_at || null;
               var isHtml = payload.is_html || false;
               // Resumo: allowCopy=true, showInsertButton=true (permite inserir), com cache
               showModal(config.messages.summary_title, displayText, true, targetEditorId, fromCache, cachedAt, ticketId, true, isHtml, plainText);
            } else if (actionType === 'reply') {
               // Para reply: usar reply_html (formatado) para exibir no modal
               // payload.reply_html tem <br> tags, payload.data tem texto plano
               var displayText = payload.reply_html || payload.data || '';
               var plainText = payload.data || '';
               var fromCache = payload.from_cache || false;
               var cachedAt = payload.cached_at || null;
               var isHtml = payload.is_html || false;
               
               // Sugestão: allowCopy=true, showInsertButton=true, com cache, com HTML
               showModal(config.messages.reply_title, displayText, true, targetEditorId, fromCache, cachedAt, ticketId, true, isHtml, plainText);
            }
         })
         .catch(function(e) {
            console.error(e);
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
      if (typeof glpi_toast_error === 'function') glpi_toast_error(message);
      else alert(message);
   }

   function showModal(title, contentText, allowCopy, targetEditorId, fromCache, cachedAt, ticketId, showInsertButton, isHtml, plainTextForInsert) {
      // Por padrão, mostra botão Inserir (true)
      if (showInsertButton === undefined) showInsertButton = true;
      // Por padrão, NÃO é HTML (false = precisa escapar)
      if (isHtml === undefined) isHtml = false;
      // Se não foi fornecido texto plano separado, usa o contentText
      if (plainTextForInsert === undefined) plainTextForInsert = contentText;
      
      var modalId = 'aiassist-modal';
      var overlayId = 'aiassist-modal-overlay';

      var overlay = document.getElementById(overlayId);
      var modal = document.getElementById(modalId);

      if (!overlay) {
         overlay = document.createElement('div');
         overlay.id = overlayId;
         Object.assign(overlay.style, {
            position: 'fixed', inset: '0', background: 'rgba(15,23,42,0.45)', 
            zIndex: '1055', display: 'flex', alignItems: 'center', justifyContent: 'center'
         });
         document.body.appendChild(overlay);
         overlay.addEventListener('click', function(e) { if(e.target === overlay) overlay.style.display = 'none'; });
      }

      if (modal) modal.remove();
      
      modal = document.createElement('div');
      modal.id = modalId;
      Object.assign(modal.style, {
         maxWidth: '680px', width: '90%', background: '#ffffff', borderRadius: '16px',
         boxShadow: '0 20px 40px rgba(15,23,42,0.35)', padding: '1.5rem 1.75rem', position: 'relative', display: 'flex', flexDirection: 'column', gap: '1rem'
      });

      var copyBtnHtml = '';
      if (allowCopy) {
         copyBtnHtml = '<button type="button" class="btn btn-primary btn-sm" id="aiassist-modal-copy"><i class="ti ti-copy me-1"></i>' + config.messages.copy + '</button>';
      }

      var insertBtnHtml = '';
      if (showInsertButton) {
         insertBtnHtml = '<button type="button" class="btn btn-info btn-sm text-white" id="aiassist-modal-insert"><i class="ti ti-arrow-bar-to-down me-1"></i>' + config.messages.insert + '</button>';
      }

      // Banner de cache se from_cache === true (apenas informativo)
      var cacheBannerHtml = '';
      if (fromCache && cachedAt) {
         var isSummary = title.indexOf('Resumo') > -1;
         var cacheLabel = isSummary ? 'último resumo gerado' : 'última sugestão gerada';
         
         cacheBannerHtml = '<div class="alert alert-info mb-0" style="display: flex; align-items: center; gap: 0.75rem;">' +
            '<i class="ti ti-info-circle" style="font-size: 1.25rem;"></i>' +
            '<div style="flex: 1;">' +
               '<strong>Este é o ' + cacheLabel + '</strong><br>' +
               '<small>Gerado em: ' + cachedAt + '. Não houve novas interações desde então.</small>' +
            '</div>' +
         '</div>';
      }
      
      // Botão "Gerar novo/nova" no rodapé (quando há cache)
      var forceNewBtnHtml = '';
      if (fromCache && cachedAt) {
         var isSummary = title.indexOf('Resumo') > -1;
         var btnLabel = isSummary ? 'Gerar novo resumo' : 'Gerar nova sugestão';
         
         forceNewBtnHtml = '<button type="button" class="btn btn-warning btn-sm" id="aiassist-force-new-btn">' +
            '<i class="ti ti-refresh me-1"></i>' + btnLabel +
         '</button>';
      }

      modal.innerHTML =
         '<div class="d-flex justify-content-between align-items-center">' +
            '<h5 class="mb-0 text-purple"><i class="ti ti-robot me-2"></i>' + title + '</h5>' +
            '<button type="button" class="btn-close" aria-label="' + config.messages.close + '"></button>' +
         '</div>' +
         cacheBannerHtml +
         '<div class="p-3 bg-light rounded border" style="max-height:400px; overflow:auto;">' +
            '<div id="aiassist-modal-content" style="word-break:break-word; font-family:inherit; margin:0; font-size:0.95rem; color:#333; line-height:1.6;"></div>' +
         '</div>' +
         '<div class="d-flex ' + (showInsertButton || forceNewBtnHtml ? 'justify-content-between' : 'justify-content-end') + ' gap-2 align-items-center" style="width: 100%;">' +
            (showInsertButton || forceNewBtnHtml ? '<div class="d-flex gap-2">' + insertBtnHtml + forceNewBtnHtml + '</div>' : '') +
            '<div class="d-flex gap-2">' +
               copyBtnHtml +
               '<button type="button" class="btn btn-secondary btn-sm" id="aiassist-modal-close">' + config.messages.close + '</button>' +
            '</div>' +
         '</div>';

      overlay.appendChild(modal);

      // Se isHtml=true, usa conteúdo direto (já vem formatado do backend)
      // Se isHtml=false, escapa HTML para segurança
      var safeContent;
      if (isHtml) {
         safeContent = contentText || '';
      } else {
         safeContent = (contentText || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      }
      document.getElementById('aiassist-modal-content').innerHTML = safeContent;

      // Botão "Fechar" (com texto)
      var btnClose = document.getElementById('aiassist-modal-close');
      if (btnClose) {
         btnClose.onclick = function() { overlay.style.display = 'none'; };
      }
      
      // Botão X (btn-close do Bootstrap)
      var btnX = modal.querySelector('.btn-close');
      if (btnX) {
         btnX.onclick = function() { overlay.style.display = 'none'; };
      }
      
      // Event listener para botão "Gerar nova sugestão/resumo" (quando há cache)
      var btnForceNew = document.getElementById('aiassist-force-new-btn');
      if (btnForceNew && ticketId) {
         btnForceNew.onclick = function() {
            overlay.style.display = 'none';
            
            // Detecta se é summary ou reply baseado no título do modal
            var isSummary = title.indexOf('Resumo') > -1;
            var buttonSelector = isSummary ? '.action-summary' : '.action-reply';
            
            // Buscar o botão apropriado na página
            var buttons = document.querySelectorAll(buttonSelector);
            if (buttons.length > 0) {
               var btn = buttons[0];
               // Marcar para forçar nova geração
               btn.dataset.forceNew = '1';
               // Disparar o clique
               btn.click();
               // Resetar flag após breve delay
               setTimeout(function() {
                  delete btn.dataset.forceNew;
               }, 100);
            } else {
               notifyError('Não foi possível encontrar o botão.');
            }
         };
      }
      
      var btnInsert = document.getElementById('aiassist-modal-insert');
      if (btnInsert) {
         btnInsert.onclick = function() {
            var inserted = false;
            var htmlContent = (plainTextForInsert || '').replace(/\r\n/g, '<br>').replace(/\n/g, '<br>');

            if (targetEditorId && typeof tinymce !== 'undefined') {
               var editor = tinymce.get(targetEditorId);
               if (editor && !editor.isHidden()) {
                  editor.insertContent(htmlContent);
                  inserted = true;
               }
            }

            if (!inserted && typeof tinymce !== 'undefined' && tinymce.activeEditor && !tinymce.activeEditor.isHidden()) {
               tinymce.activeEditor.insertContent(htmlContent);
               inserted = true;
            } 
            
            if (!inserted && targetEditorId) {
               var txtArea = document.getElementById(targetEditorId);
               if (txtArea) {
                  txtArea.value += (txtArea.value ? "\n\n" : "") + (plainTextForInsert || '');
                  inserted = true;
               }
            }

            if (!inserted) {
                var genericArea = document.querySelector('textarea[name="content"]');
                if (genericArea) {
                    genericArea.value += (genericArea.value ? "\n\n" : "") + (plainTextForInsert || '');
                    inserted = true;
                }
            }

            if (inserted) {
               var original = btnInsert.innerHTML;
               btnInsert.innerHTML = '<i class="ti ti-check me-1"></i>' + config.messages.inserted;
               btnInsert.classList.remove('btn-info');
               btnInsert.classList.add('btn-success');
               setTimeout(function() {
                  btnInsert.innerHTML = original;
                  btnInsert.classList.add('btn-info');
                  btnInsert.classList.remove('btn-success');
                  overlay.style.display = 'none';
               }, 1000);
            } else {
               alert(config.messages.editor_error);
            }
         };
      }

      if (allowCopy) {
         var btnCopy = document.getElementById('aiassist-modal-copy');
         btnCopy.onclick = function() {
            navigator.clipboard.writeText(plainTextForInsert).then(function() {
               var original = btnCopy.innerHTML;
               btnCopy.innerHTML = '<i class="ti ti-check me-1"></i>' + config.messages.copied;
               btnCopy.classList.remove('btn-primary');
               btnCopy.classList.add('btn-success');
               setTimeout(function() {
                  btnCopy.innerHTML = original;
                  btnCopy.classList.add('btn-primary');
                  btnCopy.classList.remove('btn-success');
               }, 2000);
            });
         };
      }

      overlay.style.display = 'flex';
   }

   function bootstrap() {
      var ticketId = getTicketId();
      if (!ticketId) return;

      var scanAndInject = function() {
         var containers = findAllRelevantContainers();
         containers.forEach(function(obj) {
            ensureButtons(obj, ticketId);
         });
      };

      scanAndInject();

      // Proteção: só observa se body existir
      if (document.body) {
         var observer = new MutationObserver(function() {
            scanAndInject();
         });

         observer.observe(document.body, {
            childList: true,
            subtree: true
         });
      }
   }

   if (document.readyState === 'loading') {
      if (document.addEventListener) {
         document.addEventListener('DOMContentLoaded', bootstrap);
      }
   } else {
      bootstrap();
   }

   // Expõe o modal globalmente para uso pela aba AI Assist
   window.aiassistShowModal = showModal;
})();
<?php
/**
 * Serve CSS customizado para os botões de IA na timeline/resposta
 * IMPORTANTE: NÃO incluir inc/includes.php (gera HTML antes dos headers)
 */

// Define headers ANTES de qualquer output
header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

?>
/* Botões de Ação IA (Resumo e Sugestão) com gradiente roxo */
.action-summary,
.action-reply {
   background: linear-gradient(135deg, #7c3aed 0%, #a855f7 50%, #c084fc 100%) !important;
   /* Mantemos uma borda transparente de 1px para igualar a altura dos botões padrão que têm borda */
   border: 1px solid transparent !important;
   color: #ffffff !important;
   font-weight: 500 !important;
   transition: all 0.3s ease !important;
   box-shadow: 0 4px 15px rgba(124, 58, 237, 0.3) !important;
   /* Removemos o border-radius fixo para herdar o padrão do GLPI */
}

.action-summary:hover,
.action-reply:hover {
   background: linear-gradient(135deg, #6d28d9 0%, #9333ea 50%, #a855f7 100%) !important;
   transform: translateY(-2px) !important;
   box-shadow: 0 6px 20px rgba(124, 58, 237, 0.4) !important;
   color: #ffffff !important;
}

.action-summary:active,
.action-reply:active {
   transform: translateY(0) !important;
   box-shadow: 0 2px 10px rgba(124, 58, 237, 0.3) !important;
}

.action-summary:disabled,
.action-reply:disabled {
   background: linear-gradient(135deg, #a78bfa 0%, #c4b5fd 50%, #ddd6fe 100%) !important;
   opacity: 0.7 !important;
   cursor: not-allowed !important;
   transform: none !important;
   box-shadow: none !important;
}

.action-summary i,
.action-reply i {
   color: #ffffff !important;
}

/* Espaçamento extra caso os botões fiquem colados */
.answer-action.ms-2 {
    margin-left: 0.5rem !important;
}

/* Em rodapés de formulário (card-footer), garante que botões IA não quebrem linha sozinhos */
.card-footer .answer-action {
   display: inline-flex !important;
   width: auto !important;
   flex: 0 0 auto !important;
}


<?php
/**
 * Serve CSS customizado para o botão de resumo IA na timeline
 * IMPORTANTE: NÃO incluir inc/includes.php (gera HTML antes dos headers)
 */

// Define headers ANTES de qualquer output
header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

?>
/* Botão de Resumo IA com gradiente roxo para branco */
.timeline-buttons .action-summary,
.timeline-buttons button.action-summary,
.answer-action.action-summary {
   background: linear-gradient(135deg, #7c3aed 0%, #a855f7 50%, #c084fc 100%) !important;
   border: none !important;
   color: #ffffff !important;
   font-weight: 500 !important;
   transition: all 0.3s ease !important;
   box-shadow: 0 4px 15px rgba(124, 58, 237, 0.3) !important;
}

.timeline-buttons .action-summary:hover,
.timeline-buttons button.action-summary:hover,
.answer-action.action-summary:hover {
   background: linear-gradient(135deg, #6d28d9 0%, #9333ea 50%, #a855f7 100%) !important;
   transform: translateY(-2px) !important;
   box-shadow: 0 6px 20px rgba(124, 58, 237, 0.4) !important;
}

.timeline-buttons .action-summary:active,
.timeline-buttons button.action-summary:active,
.answer-action.action-summary:active {
   transform: translateY(0) !important;
   box-shadow: 0 2px 10px rgba(124, 58, 237, 0.3) !important;
}

.timeline-buttons .action-summary:disabled,
.timeline-buttons button.action-summary:disabled,
.answer-action.action-summary:disabled {
   background: linear-gradient(135deg, #a78bfa 0%, #c4b5fd 50%, #ddd6fe 100%) !important;
   opacity: 0.7 !important;
   cursor: not-allowed !important;
}

.timeline-buttons .action-summary i,
.timeline-buttons button.action-summary i,
.answer-action.action-summary i {
   color: #ffffff !important;
}


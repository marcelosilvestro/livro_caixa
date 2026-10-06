# Changelog

## 1.0.0 — 06/10/2026

Primeira versão pública.

- Livro Caixa diário: entradas do MK-AUTH por forma de pagamento, saídas, sangria, fechamento
  encadeado (o saldo vira a abertura do dia seguinte), PDF do fechamento e relatório de sangrias.
- Conferência Bancária pela data de compensação (PIX D+0 útil, boleto e cartão D+1 útil), com
  importação do extrato e dicionário de nomes aprendidos.
- Feriados lidos da tabela compartilhada `tab_feriados` (addon Calendário de Feriados). O antigo
  calendário bancário próprio saiu.
- O Planejamento de Cobranças saiu do addon: virou a Agenda do Painel Financeiro.
- Instalador (tabelas, configuração do banco em `/opt/mk-auth/conf/livro_caixa.php`, menu em
  Financeiro › Caixa e Financeiro › Arquivos bancários, backup e diagnóstico), empacotador e
  workflow de release.
- Segurança: token CSRF em toda gravação, erros do banco fora da resposta, SweetAlert2 e SheetJS
  (0.20.3) locais em vez de CDN.

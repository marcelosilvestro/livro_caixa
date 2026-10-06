# Livro Caixa — addon para MK-AUTH

Caixa diário e conferência bancária para provedores que usam o MK-AUTH. O addon lê os
pagamentos que o próprio MK-AUTH registra (`sis_lanc`) e cuida do que fica de fora dele: saídas,
sangria, fechamento do dia e a conferência com o extrato do banco.

- **Livro Caixa:** um dia por vez, com as entradas do MK-AUTH por forma de pagamento, as saídas
  do dia, a sangria e o fechamento. O saldo de um dia fechado vira a abertura do dia seguinte, e
  um dia só fecha depois do anterior. Gera o PDF do fechamento e o relatório de sangrias do mês.
- **Conferência Bancária:** importa o extrato (`.xls`/`.xlsx`) e confere com os lançamentos pela
  **data de compensação** (PIX em D+0 útil, boleto e cartão em D+1 útil). Os nomes do extrato que
  você liga a um cliente ficam guardados e passam a ser reconhecidos sozinhos.

## Instalação

No servidor do MK-AUTH, como root:

```sh
wget -O - https://raw.githubusercontent.com/marcelosilvestro/livro_caixa/main/instalar.sh | bash
```

Sem internet no servidor: `bash instalar.sh --pacote=livro_caixa-X.Y.Z.tar.gz`.

O mesmo comando instala, atualiza e repara. O instalador:

1. acha o acesso ao banco (variáveis `LC_DB_USER`/`LC_DB_PASS`, instalação anterior,
   `/opt/mk-auth/conf/secrets.php` ou o padrão de fábrica) e grava em
   `/opt/mk-auth/conf/livro_caixa.php` (640, fora do webroot);
2. faz backup das tabelas e dos arquivos do addon antes de atualizar;
3. cria as tabelas que faltarem, sem apagar nada que já exista;
4. põe no menu **Financeiro › Caixa › Livro Caixa** e **Financeiro › Arquivos bancários ›
   Conferência Bancária**;
5. roda um diagnóstico (`bash instalar.sh --diagnostico` repete só essa parte).

Requisitos: MK-AUTH com MariaDB, PHP 8.0+ com `pdo_mysql`, `mbstring` e `json`.

## Feriados

A compensação pula sábados, domingos e feriados. Os feriados vêm da tabela compartilhada
`tab_feriados`, cadastrada pelo addon
[Calendário de Feriados](https://github.com/marcelosilvestro/calendario) — o botão
**Calendário** da conferência abre esse addon. Sem ele instalado, a tabela é criada vazia e a
conferência considera só os fins de semana.

## Tabelas

| Tabela | O que guarda |
|---|---|
| `tab_caixa_diario` | um registro por dia: abertura, sangria, totais do fechamento e status |
| `tab_saidas` | as saídas (despesas) de cada dia |
| `tab_conciliacao_nomes` | nome do extrato → login do MK-AUTH, aprendido na conferência |
| `tab_feriados` | compartilhada; só lida (dona: addon Calendário de Feriados) |

As tabelas do MK-AUTH são **só lidas**. O total em dinheiro fica congelado no fechamento; PIX,
boleto e cartão são sempre recalculados de `sis_lanc`, e o caixa avisa quando entra dinheiro
num dia já fechado.

## Segurança

- Só usuários logados no painel do MK-AUTH acessam as telas.
- Toda gravação exige POST com o token CSRF da sessão.
- A senha do banco fica em `/opt/mk-auth/conf/`, que o Apache do MK-AUTH não serve.
- SweetAlert2 e SheetJS vão junto com o addon, em `js/vendor/`; nada é carregado de CDN.

## Desenvolvimento

```sh
bash empacotar.sh        # gera pacote/livro_caixa-<versao>.tar.gz
```

Uma tag `vX.Y.Z` igual à versão do `manifest.json` publica a release com o pacote.

## Licença

MIT — veja [LICENSE](LICENSE). Bibliotecas de terceiros em `js/vendor/`: SweetAlert2 (MIT) e
SheetJS Community Edition (Apache 2.0).

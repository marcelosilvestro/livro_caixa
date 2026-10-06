-- ============================================================================================
-- livro_caixa :: schema do addon. Aplicado pelo instalador a cada instalacao/atualizacao.
--
-- Tudo idempotente (IF NOT EXISTS): rodar de novo nunca apaga dado. As tabelas do MK-AUTH
-- (sis_lanc, sis_cliente...) sao so LIDAS; o addon escreve apenas nas tabelas abaixo.
-- ============================================================================================

-- Um registro por dia de caixa. So total_dinheiro, total_entradas_geral, total_saidas,
-- valor_sangria e saldo_fechamento sao gravados no fechamento; total_pix/boleto/cartao/outros
-- ficam por compatibilidade com instalacoes antigas (o total por forma vem de sis_lanc).
CREATE TABLE IF NOT EXISTS `tab_caixa_diario` (
  `id`                   INT(11)       NOT NULL AUTO_INCREMENT,
  `data_referencia`      DATE          NOT NULL,
  `valor_abertura`       DECIMAL(12,2) DEFAULT 0.00,
  `valor_sangria`        DECIMAL(12,2) DEFAULT 0.00,
  `total_pix`            DECIMAL(12,2) DEFAULT 0.00,
  `total_dinheiro`       DECIMAL(12,2) DEFAULT 0.00,
  `total_boleto`         DECIMAL(12,2) DEFAULT 0.00,
  `total_cartao`         DECIMAL(12,2) DEFAULT 0.00,
  `total_outros`         DECIMAL(12,2) DEFAULT 0.00,
  `total_entradas_geral` DECIMAL(12,2) DEFAULT 0.00,
  `total_saidas`         DECIMAL(12,2) DEFAULT 0.00,
  `saldo_fechamento`     DECIMAL(12,2) DEFAULT 0.00,
  `status`               ENUM('aberto','fechado') DEFAULT 'aberto',
  PRIMARY KEY (`id`),
  UNIQUE KEY `data_referencia` (`data_referencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Saidas (despesas) do dia.
CREATE TABLE IF NOT EXISTS `tab_saidas` (
  `id`         INT(11)       NOT NULL AUTO_INCREMENT,
  `data`       DATETIME      NOT NULL,
  `categoria`  VARCHAR(100)  DEFAULT NULL,
  `descricao`  VARCHAR(255)  DEFAULT NULL,
  `observacao` TEXT          DEFAULT NULL,
  `valor`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `usuario`    VARCHAR(20)   DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `data` (`data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Conferencia bancaria: nome que aparece no extrato -> login do MK-AUTH. O mesmo pagador pode
-- quitar contas de varios logins, por isso a chave e o PAR (nome, login), nunca so o nome.
CREATE TABLE IF NOT EXISTS `tab_conciliacao_nomes` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `nome_banco`   VARCHAR(255) NOT NULL,
  `login_mkauth` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unq_nome_login` (`nome_banco`, `login_mkauth`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================================
-- CONTRATO COMPARTILHADO: tab_feriados (dono: addon calendario)
--
-- A conferencia bancaria so LE esta tabela (compensacao D+1 util). Os feriados se cadastram no
-- addon Calendario de Feriados. Se ele nao estiver instalado, a tabela e criada vazia com o
-- MESMO DDL do calendario — mudou la, muda aqui.
--
-- Regra de leitura em todos os addons: dia nao util = sabado, domingo ou tipo = 'feriado'.
-- ============================================================================================
CREATE TABLE IF NOT EXISTS `tab_feriados` (
  `data` DATE         NOT NULL,
  `nome` VARCHAR(150) NOT NULL,
  `tipo` ENUM('feriado','dia_util') NOT NULL DEFAULT 'feriado',
  PRIMARY KEY (`data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `tab_feriados`
  ADD COLUMN IF NOT EXISTS `abrangencia` ENUM('nacional','estadual','municipal','outro') NOT NULL DEFAULT 'nacional',
  ADD COLUMN IF NOT EXISTS `origem` VARCHAR(20) NOT NULL DEFAULT 'manual',
  ADD COLUMN IF NOT EXISTS `alterado_por` VARCHAR(60) NULL,
  ADD COLUMN IF NOT EXISTS `alterado_em` DATETIME NULL;

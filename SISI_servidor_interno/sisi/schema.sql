-- =====================================================================
--  Painel Unificado SISI · banco de dados interno (MySQL / MariaDB)
--  Importar no phpMyAdmin: aba "Importar" → escolher este arquivo → Executar.
--  Compatível com MySQL 5.7+ / MariaDB 10.2+ (usa JSON como LONGTEXT para
--  máxima compatibilidade; não depende do tipo JSON nativo).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `sisi` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sisi`;

-- Estado compartilhado do gestor (substitui o bin do jsonbin.io):
-- execução 2026, semana anterior, prazos, RaP, planejamento, credenciais, ciclo, avisos.
CREATE TABLE IF NOT EXISTS `estado` (
  `chave`         VARCHAR(64)  NOT NULL,
  `conteudo`      LONGTEXT     NOT NULL,
  `atualizado_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `atualizado_por` VARCHAR(120) NULL,
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pacote de cada OM (tarefas, registros, necessidades, governança, auditoria).
-- Guarda-se a versão vigente; o histórico de envios fica em `pacotes_hist`.
CREATE TABLE IF NOT EXISTS `pacotes` (
  `om`            VARCHAR(40)  NOT NULL,
  `om_nome`       VARCHAR(160) NULL,
  `ods`           VARCHAR(20)  NULL,
  `saved_at`      VARCHAR(40)  NULL,          -- ISO-8601 gravado pelo painel (ordem lexicográfica = cronológica)
  `enviado_em`    VARCHAR(40)  NULL,
  `enviado_por`   VARCHAR(160) NULL,
  `conteudo`      LONGTEXT     NOT NULL,      -- JSON do pacote (sem as fotos, que ficam em arquivos)
  `fotos`         INT          NOT NULL DEFAULT 0,
  `atualizado_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`om`),
  KEY `ix_ods` (`ods`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pacotes_hist` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `om`          VARCHAR(40)  NOT NULL,
  `saved_at`    VARCHAR(40)  NULL,
  `enviado_em`  VARCHAR(40)  NULL,
  `enviado_por` VARCHAR(160) NULL,
  `tamanho_kb`  INT          NULL,
  `fotos`       INT          NOT NULL DEFAULT 0,
  `conteudo`    LONGTEXT     NOT NULL,
  `recebido_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_om_data` (`om`, `recebido_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fotos dos registros: o arquivo fica em uploads/<om>/<nome>; aqui só o índice.
CREATE TABLE IF NOT EXISTS `fotos` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `om`          VARCHAR(40)  NOT NULL,
  `nome`        VARCHAR(200) NOT NULL,
  `mime`        VARCHAR(80)  NULL,
  `registro_id` VARCHAR(80)  NULL,
  `data_reg`    VARCHAR(40)  NULL,
  `tamanho`     INT          NULL,
  `caminho`     VARCHAR(300) NOT NULL,
  `recebido_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `ux_om_nome` (`om`, `nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria consolidada: cópia linear das entradas de omData.auditoria de cada pacote,
-- para consulta direta no phpMyAdmin (quem entrou, o que alterou, quando).
CREATE TABLE IF NOT EXISTS `auditoria` (
  `id`        BIGINT AUTO_INCREMENT PRIMARY KEY,
  `om`        VARCHAR(40)  NOT NULL,
  `ts`        VARCHAR(40)  NOT NULL,
  `evento`    VARCHAR(20)  NOT NULL,     -- login · edicao · envio · gov · resumo
  `acao`      VARCHAR(200) NULL,
  `detalhe`   TEXT         NULL,
  `posto`     VARCHAR(40)  NULL,
  `nome`      VARCHAR(160) NULL,
  `repeticoes` INT         NOT NULL DEFAULT 1,
  `hash`      CHAR(40)     NOT NULL,     -- sha1(om|ts|evento|acao|detalhe|nome) — evita duplicar na reimportação
  UNIQUE KEY `ux_hash` (`hash`),
  KEY `ix_om_ts` (`om`, `ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ciência de avisos (comunicação).
CREATE TABLE IF NOT EXISTS `ciencias` (
  `id`       BIGINT AUTO_INCREMENT PRIMARY KEY,
  `msg_id`   VARCHAR(80)  NOT NULL,
  `texto`    VARCHAR(255) NULL,
  `usuario`  VARCHAR(160) NULL,
  `perfil`   VARCHAR(20)  NULL,
  `escopo`   VARCHAR(40)  NULL,
  `quando`   VARCHAR(40)  NULL,
  `recebido_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_msg` (`msg_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rol de OMs (sigla, nome, ODS, guarnição), editado pelo gestor na Área Admin.
CREATE TABLE IF NOT EXISTS `roster` (
  `om`        VARCHAR(40)  NOT NULL,
  `nome`      VARCHAR(160) NULL,
  `ods`       VARCHAR(20)  NULL,
  `guarnicao` VARCHAR(10)  NULL,
  `extra`     TEXT         NULL,         -- demais campos, em JSON
  PRIMARY KEY (`om`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de acesso à API (diagnóstico; pode ser esvaziado periodicamente).
CREATE TABLE IF NOT EXISTS `api_log` (
  `id`      BIGINT AUTO_INCREMENT PRIMARY KEY,
  `quando`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`      VARCHAR(64)  NULL,
  `metodo`  VARCHAR(8)   NULL,
  `rota`    VARCHAR(40)  NULL,
  `om`      VARCHAR(40)  NULL,
  `ok`      TINYINT(1)   NOT NULL DEFAULT 1,
  `erro`    VARCHAR(255) NULL,
  `bytes`   INT          NULL,
  KEY `ix_quando` (`quando`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuário da aplicação com o mínimo de privilégios (troque a senha!).
-- Se o seu phpMyAdmin não permitir CREATE USER, peça ao administrador do servidor.
-- CREATE USER IF NOT EXISTS 'sisi_app'@'localhost' IDENTIFIED BY 'TROQUE-ESTA-SENHA';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON `sisi`.* TO 'sisi_app'@'localhost';
-- FLUSH PRIVILEGES;

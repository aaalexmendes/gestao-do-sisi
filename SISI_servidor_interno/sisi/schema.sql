-- =====================================================================
--  Painel Unificado SISI · banco de dados interno (MySQL / MariaDB)
--  Variante servidor interno u2.30-srv · API sisi-api-mysql 2.0
--
--  Importar no phpMyAdmin: aba "Importar" → escolher este arquivo → Executar
--  (ou: mysql -u root -p < schema.sql).
--  Compatível com MySQL 5.7+ / 8.x e MariaDB 10.3+. O JSON fica em LONGTEXT
--  para não depender do tipo JSON nativo.
--
--  Pode ser reimportado: só cria o que falta (CREATE TABLE IF NOT EXISTS)
--  e o rol inicial usa INSERT IGNORE (não sobrescreve edições do gestor).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `sisi` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sisi`;

-- ---------------------------------------------------------------------
-- 1. Dados do painel (mesmos da versão 1.0)
-- ---------------------------------------------------------------------

-- Estado compartilhado do gestor (substitui o bin do jsonbin.io):
-- execução 2026, semana anterior, prazos, RaP, planejamento, ciclo, avisos.
-- As credenciais NÃO ficam mais aqui: a API remove o campo "auth" ao gravar.
CREATE TABLE IF NOT EXISTS `estado` (
  `chave`          VARCHAR(64)  NOT NULL,
  `conteudo`       LONGTEXT     NOT NULL,
  `atualizado_em`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
-- As fotos só são servidas pela api.php (rota=foto), com sessão e escopo conferidos.
CREATE TABLE IF NOT EXISTS `fotos` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `om`          VARCHAR(40)  NOT NULL,
  `nome`        VARCHAR(200) NOT NULL,
  `mime`        VARCHAR(80)  NULL,          -- tipo DETECTADO no conteúdo (não o informado pelo navegador)
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
  `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
  `om`         VARCHAR(40)  NOT NULL,
  `ts`         VARCHAR(40)  NOT NULL,
  `evento`     VARCHAR(20)  NOT NULL,     -- login · edicao · envio · gov · resumo
  `acao`       VARCHAR(200) NULL,
  `detalhe`    TEXT         NULL,
  `posto`      VARCHAR(40)  NULL,
  `nome`       VARCHAR(160) NULL,
  `repeticoes` INT          NOT NULL DEFAULT 1,
  `hash`       CHAR(40)     NOT NULL,     -- sha1(om|ts|evento|acao|detalhe|nome) — evita duplicar na reimportação
  UNIQUE KEY `ux_hash` (`hash`),
  KEY `ix_om_ts` (`om`, `ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ciência de avisos (comunicação). Usuário, perfil e escopo são gravados
-- pela API a partir da sessão (não do que o navegador informa).
CREATE TABLE IF NOT EXISTS `ciencias` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `msg_id`      VARCHAR(80)  NOT NULL,
  `texto`       VARCHAR(255) NULL,
  `usuario`     VARCHAR(160) NULL,
  `perfil`      VARCHAR(20)  NULL,
  `escopo`      VARCHAR(40)  NULL,
  `quando`      VARCHAR(40)  NULL,
  `recebido_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_msg` (`msg_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rol de OMs (sigla, nome, ODS, guarnição). É a FONTE DE AUTORIDADE do escopo:
-- a API decide a qual ODS cada OM pertence por esta tabela (nunca pelo pacote).
-- Editado pelo gestor na Área Admin; a carga inicial está no fim deste arquivo.
CREATE TABLE IF NOT EXISTS `roster` (
  `om`        VARCHAR(40)  NOT NULL,
  `nome`      VARCHAR(160) NULL,
  `ods`       VARCHAR(20)  NULL,
  `guarnicao` VARCHAR(10)  NULL,
  `extra`     TEXT         NULL,         -- demais campos, em JSON
  PRIMARY KEY (`om`),
  KEY `ix_ods` (`ods`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de acesso à API (diagnóstico; pode ser esvaziado periodicamente).
CREATE TABLE IF NOT EXISTS `api_log` (
  `id`      BIGINT AUTO_INCREMENT PRIMARY KEY,
  `quando`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`      VARCHAR(64)  NULL,
  `metodo`  VARCHAR(8)   NULL,
  `rota`    VARCHAR(40)  NULL,
  `om`      VARCHAR(40)  NULL,
  `ok`      TINYINT(1)   NOT NULL DEFAULT 1,
  `erro`    VARCHAR(255) NULL,
  `bytes`   INT          NULL,
  KEY `ix_quando` (`quando`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Controle de acesso (novo na API 2.0)
-- ---------------------------------------------------------------------

-- Usuários do painel. A senha é conferida SOMENTE no servidor.
--   senha_hash   → password_hash() do PHP (Argon2id quando disponível; senão bcrypt).
--   legado_*     → hash herdado do painel antigo: sha256(salt + senha). Vale apenas
--                  até o primeiro acesso; na troca obrigatória é apagado.
--   perfil/escopo → gestor (escopo vazio) · ods (escopo = sigla do ODS) · om (escopo = sigla da OM)
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `login`             VARCHAR(120) NOT NULL,
  `nome`              VARCHAR(160) NOT NULL,
  `posto`             VARCHAR(40)  NULL,
  `om_lotacao`        VARCHAR(80)  NULL,      -- OM onde o usuário serve (informativo)
  `tel`               VARCHAR(40)  NULL,
  `email`             VARCHAR(160) NULL,
  `perfil`            VARCHAR(10)  NOT NULL,  -- gestor | ods | om
  `escopo`            VARCHAR(40)  NULL,
  `ativo`             TINYINT(1)   NOT NULL DEFAULT 1,
  `senha_hash`        VARCHAR(255) NULL,
  `legado_salt`       VARCHAR(64)  NULL,
  `legado_hash`       CHAR(64)     NULL,
  `troca_obrigatoria` TINYINT(1)   NOT NULL DEFAULT 1,
  `criado_em`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `alterado_em`       DATETIME     NULL,
  `senha_alterada_em` DATETIME     NULL,
  `ultimo_login`      DATETIME     NULL,
  UNIQUE KEY `ux_login` (`login`),
  KEY `ix_perfil_escopo` (`perfil`, `escopo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessões. O navegador guarda só um cookie HttpOnly com um valor aleatório de
-- 256 bits; aqui fica apenas o SHA-256 desse valor (um vazamento do banco não
-- permite assumir sessões). Validade absoluta: 'sessao_horas' do config.php (8 h).
CREATE TABLE IF NOT EXISTS `sessoes` (
  `token_hash` CHAR(64)     NOT NULL,
  `usuario_id` INT          NOT NULL,
  `csrf`       CHAR(64)     NOT NULL,
  `criada_em`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expira_em`  DATETIME     NOT NULL,
  `ultimo_uso` DATETIME     NULL,
  `ip`         VARCHAR(64)  NULL,
  `user_agent` VARCHAR(255) NULL,
  PRIMARY KEY (`token_hash`),
  KEY `ix_usuario` (`usuario_id`),
  KEY `ix_expira` (`expira_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativas de login (limite por login e por IP).
CREATE TABLE IF NOT EXISTS `login_tentativas` (
  `id`     BIGINT AUTO_INCREMENT PRIMARY KEY,
  `quando` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`     VARCHAR(64)  NULL,
  `login`  VARCHAR(120) NULL,
  `ok`     TINYINT(1)   NOT NULL DEFAULT 0,
  KEY `ix_login_quando` (`login`, `quando`),
  KEY `ix_ip_quando` (`ip`, `quando`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eventos de segurança (login, falha, logout, troca de senha, acesso negado,
-- cadastro de usuários). Consulta típica:
--   SELECT * FROM seguranca_log WHERE evento IN ('login_falha','negado') ORDER BY id DESC LIMIT 100;
CREATE TABLE IF NOT EXISTS `seguranca_log` (
  `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
  `quando`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `evento`     VARCHAR(30)  NOT NULL,
  `usuario_id` INT          NULL,
  `login`      VARCHAR(120) NULL,
  `ip`         VARCHAR(64)  NULL,
  `rota`       VARCHAR(40)  NULL,
  `detalhe`    VARCHAR(255) NULL,
  KEY `ix_quando` (`quando`),
  KEY `ix_evento` (`evento`, `quando`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Usuário da aplicação no MySQL (menor privilégio)
-- ---------------------------------------------------------------------
-- Execute como administrador do MySQL, trocando a senha. A aplicação só precisa
-- de SELECT, INSERT, UPDATE e DELETE no banco `sisi` (sem DROP/CREATE/ALTER/GRANT).
-- Se o MySQL estiver em outra máquina, troque 'localhost' pelo IP do servidor web.
-- Se o phpMyAdmin não permitir CREATE USER, peça ao administrador do servidor.
--
-- CREATE USER IF NOT EXISTS 'sisi_app'@'localhost' IDENTIFIED BY 'TROQUE-ESTA-SENHA';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON `sisi`.* TO 'sisi_app'@'localhost';
-- FLUSH PRIVILEGES;

-- ---------------------------------------------------------------------
-- 4. Rol inicial de OMs (gerado a partir do painel u2.30 pelo script de build)
-- ---------------------------------------------------------------------
-- [ROL-INICIAL]
-- 69 OMs do ROSTER_EMBED da u2.30. INSERT IGNORE: não sobrescreve o que o gestor já editou.
INSERT IGNORE INTO `roster` (`om`, `nome`, `ods`, `guarnicao`, `extra`) VALUES
  ('AFA', 'ACADEMIA DA FORCA AEREA', 'COMGEP', 'YS', NULL),
  ('BAAF', 'BASE AEREA DOS AFONSOS', 'COMPREP', 'AF', NULL),
  ('BAAN', 'BASE AEREA DE ANAPOLIS', 'COMPREP', 'NA', NULL),
  ('BABE', 'BASE AEREA DE BELEM', 'COMPREP', 'BE', NULL),
  ('BABR', 'BASE AEREA DE BRASILIA', 'COMPREP', 'BR', NULL),
  ('BABV', 'BASE AEREA DE BOA VISTA', 'COMPREP', 'BV', NULL),
  ('BACG', 'BASE AEREA DE CAMPO GRANDE', 'COMPREP', 'CG', NULL),
  ('BACO', 'BASE AEREA DE CANOAS', 'COMPREP', 'CO', NULL),
  ('BAFL', 'BASE AEREA DE FLORIANOPOLIS', 'DECEA', 'FL', NULL),
  ('BAFZ', 'BASE AEREA DE FORTALEZA', 'DCTA', 'FZ', NULL),
  ('BAGL', 'BASE AEREA DO GALEAO', 'COMPREP', 'GL', NULL),
  ('BAMN', 'BASE AEREA DE MANAUS', 'COMPREP', 'MN', NULL),
  ('BANT', 'BASE AEREA DE NATAL', 'COMPREP', 'NT', NULL),
  ('BAPV', 'BASE AEREA DE PORTO VELHO', 'COMPREP', 'PV', NULL),
  ('BARF', 'BASE AEREA DE RECIFE', 'DECEA', 'RF', NULL),
  ('BASC', 'BASE AEREA DE SANTA CRUZ', 'COMPREP', 'SC', NULL),
  ('BASM', 'BASE AEREA DE SANTA MARIA', 'COMPREP', 'SM', NULL),
  ('BASP', 'BASE AEREA DE SAO PAULO', 'COMPREP', 'SP', NULL),
  ('BAST', 'BASE AEREA DE SANTOS', 'COMPREP', 'ST', NULL),
  ('BASV', 'BASE AEREA DE SALVADOR', 'COMPREP', 'SV', NULL),
  ('CAE', 'CENTRO DE AQUISICOES ESPECIFICAS', 'SEFA', 'GL', NULL),
  ('CEMAL', 'CENTRO DE MEDICINA AEROESPACIAL', 'COMGEP', 'GL', NULL),
  ('CIAAR', 'CENTRO DE INSTR. E ADAPTACAO DA AERONAUTICA', 'COMGEP', 'LS', NULL),
  ('CINDACTA 1', '', 'DECEA', 'BR', NULL),
  ('CINDACTA 2', '', 'DECEA', 'CT', NULL),
  ('CINDACTA 3', '', 'DECEA', 'RF', NULL),
  ('CINDACTA 4', '', 'DECEA', 'MN', NULL),
  ('CLA', 'CENTRO DE LANCAMENTO DE ALCANTARA', 'DCTA', 'AK', NULL),
  ('CLBI', 'CENTRO DE LANCAMENTO DA BARREIRA DO INFERNO', 'DCTA', 'NT', NULL),
  ('COMAR 1', 'PRIMEIRO COMANDO AEREO REGIONAL', 'COMPREP', 'BE', NULL),
  ('COMAR 2', 'SEGUNDO COMANDO AEREO REGIONAL', 'COMPREP', 'RF', NULL),
  ('COMAR 3', 'TERCEIRO COMANDO AEREO REGIONAL', 'COMPREP', 'RJ', NULL),
  ('COMAR 4', 'QUARTO COMANDO AEREO REGIONAL', 'COMPREP', 'SP', NULL),
  ('COMAR 5', 'QUINTO COMANDO AEREO REGIONAL', 'COMPREP', 'CO', NULL),
  ('COMAR 6', 'SEXTO COMANDO AEREO REGIONAL', 'COMPREP', 'BR', NULL),
  ('COMAR 7', 'SETIMO COMANDO AEREO REGIONAL', 'COMPREP', 'MN', NULL),
  ('COMARA', 'COMISSAO DE AEROPORTOS DA REGIAO AMAZONICA', 'COMGAP', 'BE', NULL),
  ('COMGAP', 'COMANDO GERAL DE APOIO', 'COMGAP', 'SP', NULL),
  ('CPBV', 'CAMPO DE PROVAS BRIGADEIRO VELLOSO', 'COMPREP', 'CC', NULL),
  ('CRCEA-SE', '', 'DECEA', 'SP', NULL),
  ('DCTA', 'DEPARTAMENTO DE CIENCIA E TECNOL AEROESPACIAL', 'DCTA', 'SJ', NULL),
  ('DECEA', '', 'DECEA', 'RJ', NULL),
  ('DIRAD', 'DIRETORIA DE ADMINISTRACAO DA AERONAUTICA', 'SEFA', 'AF', NULL),
  ('DIRAP', 'DIRETORIA DE ADMINISTRACAO DO PESSOAL', 'COMGEP', 'GL', NULL),
  ('DIRSA', 'DIRETORIA DE SAUDE', 'COMGEP', 'GL', NULL),
  ('EEAR', 'ESCOLA DE ESPECIALISTAS DE AERONAUTICA', 'COMGEP', 'GW', NULL),
  ('EPCAR', 'ESCOLA PREPARATORIA DE CADETES DO AR', 'COMGEP', 'BQ', NULL),
  ('GAP-BR', 'GRUPAMENTO DE APOIO DE BRASILIA', 'SEFA', 'BR', NULL),
  ('GAP-RJ', 'GRUPAMENTO DE APOIO DO RIO DE JANEIRO', 'SEFA', 'RJ', NULL),
  ('HAAF', 'HOSPITAL DE AERONAUTICA DOS AFONSOS', 'COMGEP', 'AF', NULL),
  ('HABE', 'HOSPITAL DE AERONAUTICA DE BELEM', 'COMGEP', 'BE', NULL),
  ('HARF', 'HOSPITAL DA AERONAUTICA DE RECIFE', 'COMGEP', 'RF', NULL),
  ('HCA', 'HOSPITAL CENTRAL AERONAUTICA', 'COMGEP', 'RJ', NULL),
  ('HFAB', 'HOSPITAL DE FORCA AEREA DE BRASILIA', 'COMGEP', 'BR', NULL),
  ('HFAG', 'HOSPITAL DE FORCA AEREA DO GALEAO', 'COMGEP', 'GL', NULL),
  ('HFASP', 'HOSPITAL DE FORCA AEREA DE SAO PAULO', 'COMGEP', 'SP', NULL),
  ('IAE', '', 'DCTA', 'SJ', NULL),
  ('IEAV', 'INSTITUTO DE ESTUDOS AVANCADOS', 'DCTA', 'SJ', NULL),
  ('LAQFA', 'LABORATORIO DE ANALISES QUIMICAS', 'COMGEP', 'GL', NULL),
  ('PAAF', 'PREFEITURA DE AERONAUTICA DOS AFONSOS', 'SEFA', 'AF', NULL),
  ('PABE', 'PREFEITURA DE AERONAUTICA DE BELEM', 'SEFA', 'BE', NULL),
  ('PABR', 'PREFEITURA DE AERONAUTICA DE BRASILIA', 'SEFA', 'BR', NULL),
  ('PAGL', 'PREFEITURA DE AERONAUTICA DO GALEAO', 'SEFA', 'GL', NULL),
  ('PAMA-GL', 'PARQUE MATERIAL AERONAUTICO DO GALEAO', 'COMGAP', 'GL', NULL),
  ('PAMA-LS', 'PARQUE MATERIAL AERONAUTICO DE LAGOA SANTA', 'COMGAP', 'LS', NULL),
  ('PAMA-SP', 'PARQUE DE MATERIAL AERONAUTICO DE SAO PAULO', 'COMGAP', 'SP', NULL),
  ('PAMB-RJ', 'PARQUE DE MATERIAL BELICO DA AERONAUTICA', 'COMGAP', 'GL', NULL),
  ('PAME-RJ', '', 'DECEA', 'RJ', NULL),
  ('UNIFA', 'UNIVERSIDADE DA FORCA AEREA', 'COMGEP', 'AF', NULL);
-- [/ROL-INICIAL]

# Painel Unificado SISI — implantação no servidor interno (MySQL / PHP)

Este pacote substitui, dentro da rede da OM, os três serviços externos que o painel usava
(jsonbin.io, Google Apps Script/Drive e a "API" opcional). Nada muda para quem usa o painel:
os formatos de dados são os mesmos — só o endereço do servidor é outro.

## O que vai para o servidor

```
sisi/                        ← pasta pública no servidor web (Apache + PHP)
├── index.html               ← o painel (Painel_Unificado_SISI_u2_28.html renomeado)
├── api.php                  ← a API (estado, pacotes, fotos, auditoria, ciências, rol)
├── config.php               ← COPIAR de config.exemplo.php e preencher (senha do MySQL + token)
├── config.exemplo.php
├── schema.sql               ← importar no phpMyAdmin UMA vez
├── .htaccess                ← bloqueia leitura do config.php e do .sql
└── uploads/                 ← fotos das OMs (precisa de permissão de escrita)
    └── .htaccess
```

## Pré-requisitos (confirmar com a TI da OM)

| Item | Mínimo | Como verificar |
|---|---|---|
| PHP | 7.4 ou superior (testado em 8.3) | `php -v` ou criar `info.php` com `<?php phpinfo();` e abrir no navegador (apagar depois) |
| Extensão `pdo_mysql` | obrigatória | aparece no phpinfo como "PDO drivers: mysql" |
| MySQL / MariaDB | 5.7+ / 10.2+ | cabeçalho do phpMyAdmin |
| Apache com `mod_rewrite`/`.htaccess` | recomendado | se for Nginx ou IIS, ver "Outros servidores" abaixo |
| Limite de upload do PHP | ≥ 64 MB (`post_max_size`, `upload_max_filesize`) | phpinfo; o `.htaccess` tenta elevar para 80 MB |

A extensão `mbstring` é opcional — a API funciona sem ela.

## Passo a passo

### 1. Banco de dados (phpMyAdmin)
1. Abra o phpMyAdmin → aba **Importar** → escolha `schema.sql` → **Executar**.
   Ele cria o banco `sisi` com 8 tabelas (`estado`, `pacotes`, `pacotes_hist`, `fotos`,
   `auditoria`, `ciencias`, `roster`, `api_log`).
2. Crie um usuário só para o painel (aba **Contas de usuário** → Adicionar):
   nome `sisi_app`, host `localhost`, senha forte, privilégios **somente no banco `sisi`**:
   SELECT, INSERT, UPDATE, DELETE. (Não precisa de DROP/CREATE/ALTER.)
   Se o phpMyAdmin não permitir criar usuário, peça ao administrador.

### 2. Configuração
1. Copie `config.exemplo.php` para `config.php`.
2. Preencha `db_user`, `db_pass` (os da etapa 1) e, se o MySQL não estiver na mesma máquina, `db_host`.
3. Gere um **token** longo e aleatório (ex.: 32+ caracteres) e coloque em `'token'`.
   Esse token é o que autoriza gravações; só o gestor precisa conhecê-lo
   (ele fica guardado no navegador do gestor e viaja no "estado" para as OMs).
4. `cors_origin`: deixe `'*'` se o painel for aberto de vários lugares; restrinja ao host
   fixo se o painel for servido sempre do mesmo endereço.

### 3. Envio pelo FileZilla
1. **Arquivo → Gerenciador de Sites → Novo site**: host = servidor web da OM (IP ou nome),
   protocolo **SFTP** se a TI oferecer (ou FTP), usuário/senha fornecidos pela TI.
2. No painel direito (servidor), vá até a pasta pública (normalmente `/var/www/html`,
   `/public_html` ou `htdocs`). Crie a pasta `sisi`.
3. Arraste para dentro de `sisi/`: `index.html`, `api.php`, `config.php`, `.htaccess`,
   a pasta `uploads/` (com o `.htaccess` dela). **Não envie** `schema.sql` nem
   `config.exemplo.php` (não fazem falta no servidor e são bloqueados pelo .htaccess de
   qualquer forma).
   - Em **Transferência → Tipo de transferência**, deixe "Automático" (os `.php`/`.html`
     vão como texto; fotos vão como binário).
   - Marque "Servidor → Forçar exibição de arquivos ocultos" para ver os `.htaccess`.
4. Permissões (botão direito → **Permissões do arquivo**):
   - `uploads/` → **775** (ou 777 se a TI permitir); o usuário do Apache precisa escrever ali.
   - `config.php` → **640** (ou 644 se o servidor exigir).
   - demais arquivos → 644.
5. Teste no navegador: `http://<servidor>/sisi/api.php?ping=1`
   Deve responder `{"ok":true,"versao":"sisi-api-mysql 1.0",...}`.
   Se responder `config.php ausente`, o arquivo não subiu ou está com outro nome.
   Se responder `banco indisponível`, revise usuário/senha/host em `config.php`.
   Teste também `http://<servidor>/sisi/config.php` — **deve dar 403** (bloqueado).

### 4. Ligar o painel ao servidor
1. Abra `http://<servidor>/sisi/index.html` como **gestor**.
2. Área Administrativa → cartão **"Servidor interno · MySQL / PHP"**:
   URL = `http://<servidor>/sisi/api.php`, Token = o mesmo do `config.php` → **Salvar e testar**.
   Ao salvar, o painel aponta as três sincronizações para o servidor interno.
3. No cartão "Compartilhamento", clique **Enviar** uma vez: o estado atual
   (execução 2026, prazos, RaP, planejamento, credenciais, avisos) vai para a tabela `estado`.
4. Em "Integração com o Google Apps Script" nada precisa ser feito: a URL já foi trocada
   automaticamente pela da api.php.
5. Cada OM, ao entrar no painel pelo novo endereço, passa a enviar seus lançamentos
   para o MySQL. O gestor puxa como antes ("Puxar lançamentos das OMs").

### 5. Migrar o que já existe (opcional)
- **Estado do gestor**: basta a etapa 4.3 — o conteúdo que estava no navegador do gestor
  é o mais recente.
- **Pacotes das OMs que estão no Drive**: no painel antigo (ainda apontando para o Apps
  Script), gestor → "Puxar lançamentos das OMs"; depois troque para o servidor interno e
  use "Enviar" no cartão de compartilhamento — isso leva o estado, mas **não** os pacotes.
  Para os pacotes, cada OM precisa entrar e clicar "Enviar ao servidor" uma vez (os dados
  estão no navegador dela). Alternativa: exporte os pacotes pelo Admin do projeto
  (`Baixar consolidado`) e importe no painel novo — ou me envie o JSON que eu gero o SQL.
- **Fotos**: ficam no Drive; só as novas vão para `uploads/`. Se quiser trazer as antigas,
  baixe a pasta do Drive e envie por FileZilla para `uploads/<SIGLA>/`, mantendo os nomes.

## Como os dados ficam no MySQL (para consultas diretas no phpMyAdmin)

| Tabela | Conteúdo | Consulta útil |
|---|---|---|
| `estado` | 1 linha (`chave='sisi'`): JSON com execução 2026, prazos, RaP, planejamento, credenciais, avisos | `SELECT atualizado_em, LENGTH(conteudo)/1024 AS kb FROM estado;` |
| `pacotes` | versão vigente de cada OM | `SELECT om, ods, saved_at, enviado_por, fotos FROM pacotes ORDER BY saved_at DESC;` |
| `pacotes_hist` | últimos 30 envios de cada OM (`hist_max`) | `SELECT om, enviado_em, enviado_por, tamanho_kb FROM pacotes_hist WHERE om='BAPV' ORDER BY id DESC;` |
| `auditoria` | uma linha por evento (quem entrou, o que alterou, quando) | `SELECT * FROM auditoria WHERE om='BAPV' ORDER BY ts DESC;` |
| | OMs que nunca entraram | `SELECT r.om FROM roster r LEFT JOIN auditoria a ON a.om=r.om AND a.evento='login' WHERE a.id IS NULL;` |
| `fotos` | índice das fotos (arquivo em `uploads/<OM>/`) | `SELECT om, COUNT(*) FROM fotos GROUP BY om;` |
| `ciencias` | ciência dos avisos | `SELECT usuario, texto, quando FROM ciencias ORDER BY id DESC;` |
| `api_log` | diagnóstico (IP, rota, erro) | `SELECT * FROM api_log WHERE ok=0 ORDER BY id DESC LIMIT 50;` |

O JSON das tabelas `estado` e `pacotes` é exatamente o que o painel usa; para exportar em
Excel, use a aba **Exportar** do phpMyAdmin ou os botões CSV do próprio painel.

## Backup
No phpMyAdmin: banco `sisi` → **Exportar** → formato SQL → gravar o arquivo (semanal é
suficiente). Faça também cópia da pasta `uploads/` (FileZilla, servidor → local).

## Segurança (o que está e o que não está coberto)
- Gravações exigem o token (`X-Token`, `?token=` ou campo `token` no corpo).
- Leituras do estado podem exigir um `token_leitura` separado (`config.php`); por padrão,
  qualquer máquina da rede lê o estado — o que inclui os hashes de credenciais do painel.
  **Recomendação: defina `token_leitura`.**
- `config.php` e `.sql` são bloqueados pelo `.htaccess`. Em Nginx/IIS isso tem de ser feito
  na configuração do servidor (ver abaixo).
- A API não usa HTTPS por si; se a rede interna tiver certificado, use `https://` na URL.
- Não há limitação de taxa nem bloqueio por IP — adequado para intranet, não para internet.

## Outros servidores
- **Nginx**: adicionar `location ~ ^/sisi/(config|config\.exemplo)\.php$ { deny all; }`,
  `location ~ \.sql$ { deny all; }` e `location ~ ^/sisi/uploads/.*\.php$ { deny all; }`;
  `client_max_body_size 80m;`.
- **IIS**: usar "Request Filtering" para negar `config.php` e `*.sql`; `maxAllowedContentLength`
  ≥ 80 MB.
- **XAMPP/WAMP (Windows)**: pasta pública é `htdocs/`; `uploads/` precisa de escrita para o
  usuário do Apache; `.htaccess` funciona se `AllowOverride All` estiver no `httpd.conf`.

## Problemas comuns
| Sintoma | Causa provável |
|---|---|
| `token inválido` ao enviar | token do painel ≠ `config.php` (espaços em branco, copiar/colar) |
| `não foi possível criar a pasta de fotos` | `uploads/` sem permissão de escrita |
| `pacote maior que o limite` | muitas fotos num envio; aumentar `max_mb` e `post_max_size` |
| Painel abre, mas "Conexão OK" não aparece | URL sem `/api.php` no fim, ou porta/host errados |
| Tudo funciona no navegador do gestor mas não nos das OMs | elas abriram o painel pelo endereço antigo (GitHub); use o endereço interno |
| `HTTP 403` no `?ping=1` | o `.htaccess` está bloqueando `api.php` — confira se o nome do arquivo é exatamente `api.php` |

## Validação realizada antes da entrega
Testado contra MariaDB 10.11 + PHP 8.3 (sem `mbstring`): 32 verificações da API (contratos,
token, UTF-8, `{}` preservado, fotos em arquivo sem duplicar, histórico, auditoria idempotente,
CORS, siglas inválidas) e 25 de integração com o painel real (gestor publica e recarrega o
estado; OM envia pacote com foto; gestor puxa e vê a auditoria; ciência; compatibilidade
sem o servidor). O que **não** foi testado: Apache real com `.htaccess`, Windows/IIS e a sua
versão específica de PHP/MySQL — por isso a etapa 3.5 pede os dois testes no navegador.

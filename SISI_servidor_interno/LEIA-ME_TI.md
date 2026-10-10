# Painel SISI — instalação no servidor interno (para a TI)

Aplicação web estática (1 arquivo HTML) + uma API em PHP + banco MySQL/MariaDB.
Sem dependências externas na rede: nenhum acesso à internet é necessário depois de instalado.

## Requisitos
- Apache (ou Nginx/IIS — ver README_IMPLANTACAO.md) com PHP **7.4+** e a extensão **pdo_mysql**
- MySQL 5.7+ ou MariaDB 10.2+ (acesso via phpMyAdmin é suficiente)
- `post_max_size` e `upload_max_filesize` ≥ **64 MB** (o `.htaccess` incluso tenta definir 80 MB)
- Pasta `uploads/` com permissão de escrita para o usuário do servidor web

## Instalação (≈ 15 minutos)
1. **Banco**: no phpMyAdmin, importar `sisi/schema.sql` (cria o banco `sisi` com 8 tabelas).
2. **Usuário do banco**: criar `sisi_app` com senha forte e privilégios SELECT, INSERT, UPDATE, DELETE
   **somente** no banco `sisi` (o final do `schema.sql` tem o comando comentado).
3. **Configuração**: copiar `sisi/config.exemplo.php` → `sisi/config.php`; preencher
   `db_host`, `db_user`, `db_pass`; gerar um token aleatório longo em `token`
   (ex.: `openssl rand -hex 24`) e, de preferência, outro em `token_leitura`.
   Entregar os dois tokens ao gestor do painel (ele os digita uma única vez na Área Admin).
4. **Arquivos**: publicar a pasta `sisi/` inteira na raiz pública do servidor web
   (ex.: `/var/www/html/sisi/`). Permissões: `uploads/` 775; `config.php` 640; demais 644.
   Não publicar `schema.sql` nem `config.exemplo.php` (dispensáveis; o `.htaccess` também os bloqueia).
5. **Verificação** (no navegador):
   - `http://<servidor>/sisi/api.php?ping=1` → deve responder `{"ok":true,"versao":"sisi-api-mysql 1.0",...}`
   - `http://<servidor>/sisi/config.php` → deve responder **403 Forbidden**
   - `http://<servidor>/sisi/index.html` → o painel abre na tela de entrada
6. Informar ao gestor a URL final da api.php (ex.: `http://192.168.10.5/sisi/api.php`).

Opcional, em Linux com `php` e `mariadb` de linha de comando: `bash teste_api.sh`
executa 32 verificações automáticas da API (sobe um MariaDB temporário; não toca no banco real).

## Operação
- **Backup**: exportar o banco `sisi` pelo phpMyAdmin (SQL) e copiar a pasta `uploads/` — semanal.
- **Logs**: tabela `api_log` (requisições e erros); pode ser esvaziada a qualquer momento.
- **Tamanho**: cada envio de OM guarda a versão vigente em `pacotes` e as 30 últimas em
  `pacotes_hist`; fotos ficam como arquivos em `uploads/<SIGLA>/`. Volume esperado: dezenas de MB/ano.
- **Segurança**: gravações exigem token; leituras do estado exigem `token_leitura` se definido.
  A API foi projetada para **intranet**: não há HTTPS próprio nem limitação de taxa.
  Se houver certificado interno, usar `https://` na URL.

## Se algo falhar
| Resposta | O que fazer |
|---|---|
| `config.php ausente` | arquivo não foi renomeado/publicado |
| `banco indisponível: ...` | usuário/senha/host em `config.php`; o usuário tem acesso ao banco `sisi`? |
| `token inválido` | o gestor digitou um token diferente do `config.php` |
| `não foi possível criar a pasta de fotos` | `uploads/` sem permissão de escrita |
| 403 no `api.php?ping=1` | `.htaccess` do servidor bloqueando `.php` por engano, ou nome do arquivo alterado |
| 500 sem mensagem | PHP sem `pdo_mysql`, ou versão < 7.4 (`php -v`) |

Detalhes completos, consultas SQL úteis e migração dos dados atuais: `README_IMPLANTACAO.md`.

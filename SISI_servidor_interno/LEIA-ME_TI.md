# Painel SISI — instalação no servidor interno (checklist da TI)

Pacote **u2.30-srv** · API **sisi-api-mysql 2.0** · 10/10/2026

Uma página HTML, uma API em PHP e um banco MySQL/MariaDB. Depois de instalado, o painel **não usa a internet**: bibliotecas e fontes vêm da pasta `vendor/`.

## O que mudou desde o pacote u2.28 (os seis pedidos da TI)

| Pedido | Como ficou |
|---|---|
| 1. Inicialização e tokens | Aberto pelo endereço da intranet, o painel encontra sozinho a `api.php` da mesma pasta. **Não existe mais token compartilhado nos navegadores**: cada pessoa entra com usuário e senha, e a sessão fica num cookie `HttpOnly` + `SameSite=Strict` de 8 h. |
| 2. Controle de acesso no PHP | A senha é conferida na API (Argon2id/bcrypt). Gestor: tudo. ODS: só **consulta** as OMs do seu ODS. OM: lê e grava só a própria OM. Há limite de tentativas e troca obrigatória da senha no primeiro acesso. |
| 3. Leituras e imagens | Toda leitura exige sessão e respeita o escopo. Fotos só pela `api.php?rota=foto`, com nome, escopo e tipo de imagem conferidos. A pasta `uploads/` fica bloqueada para acesso direto. |
| 4. Bibliotecas locais | xlsx, papaparse, chart.js, html2canvas, jspdf e as fontes Roboto em `sisi/vendor/`, com hashes em `vendor/LEIA-ME.txt`. |
| 5. SQL, usuário e config | `schema.sql` com 12 tabelas e o rol de 69 OMs; usuário `sisi_app` com privilégio mínimo; `config.exemplo.php` revisto. |
| 6. Teste completo | 112 verificações da API, 39 do servidor e 49 no navegador (gestor e duas OMs em navegadores separados). Ver "Validação" no README. |

## Requisitos

- Apache 2.4, Nginx ou IIS 10, com **PHP 7.4 ou superior** (testado em 8.3) e as extensões `pdo_mysql` e `fileinfo` (esta é recomendada; sem ela a API confere a assinatura do arquivo).
- MySQL 5.7+/8.x ou MariaDB 10.3+ (phpMyAdmin é suficiente).
- `post_max_size` e `upload_max_filesize` de pelo menos **64 MB**.
- **HTTPS com certificado interno é fortemente recomendado**: com `http://`, a senha trafega sem cifragem na rede.

## Instalação (cerca de 20 minutos)

1. **Banco.** No phpMyAdmin, aba *Importar*, envie `sisi/schema.sql`. Ele cria o banco `sisi` com 12 tabelas e o rol de OMs. Pode ser reimportado sem perder dados.
2. **Usuário do banco.** Crie `sisi_app` com senha forte e **somente** `SELECT, INSERT, UPDATE, DELETE` no banco `sisi`. O comando está comentado no fim do `schema.sql`.
3. **Configuração.** Copie `sisi/config.exemplo.php` para `config.php` e preencha:
   - `db_host`, `db_user`, `db_pass`;
   - `token`: um valor aleatório longo (`openssl rand -hex 24`). Serve **só** para criar o gestor inicial. Entregue-o ao gestor por canal seguro;
   - `cookie_seguro => true` se o acesso for por `https://`.
4. **Arquivos.** Publique a pasta `sisi/` inteira (ex.: `/var/www/html/sisi/`). Permissões no Linux: `uploads/` com escrita para o usuário do servidor web (`chown www-data uploads; chmod 770`); `config.php` com `chown root:www-data` e `chmod 640`.
   - **Apache:** o `.htaccess` já bloqueia tudo. Exige `AllowOverride All` na pasta, e `mod_headers` é recomendado.
   - **IIS:** o `web.config` da pasta e o de `uploads/` fazem o mesmo.
   - **Nginx:** inclua `servidores/nginx_sisi.conf` no bloco `server`.
   - Recomendado: mover `uploads/` para fora da pasta pública (chave `uploads` do `config.php`).
5. **Verificação.** No navegador:
   - `.../sisi/api.php?ping=1` deve responder `{"ok":true,"versao":"sisi-api-mysql 2.0",...}`;
   - `.../sisi/config.php` e `.../sisi/uploads/` devem responder **403** (ou 404);
   - `.../sisi/index.html` deve abrir a tela de entrada com **Usuário** e **Senha**.

   Num Linux com `curl`, rode também `bash testes/teste_servidor.sh http://<servidor>/sisi`. São 39 conferências, todas somente leitura.
6. **Primeiro acesso.** O gestor abre o painel, clica em **Primeira configuração** e informa o token, o próprio usuário e a senha. Em seguida, na aba *Sincronização* da Área Admin, usa **Importar do JSONBin** (ou um arquivo `.json`) para trazer os dados e os usuários atuais.
7. **Informe ao gestor** o endereço final (ex.: `https://intranet/sisi/`).

## Operação

- **Backup semanal:** exporte o banco `sisi` (SQL) e copie a pasta `uploads/`.
- **Segurança:** a tabela `seguranca_log` registra logins, falhas, bloqueios, acessos negados e alterações de usuários. A tabela `api_log` registra as chamadas. As duas podem ser esvaziadas periodicamente.
- **Usuário bloqueado** (5 senhas erradas em 15 min): espera de 15 minutos, ou o gestor gera uma nova senha na Área Admin.
- **Gestor esqueceu a senha:** outro gestor gera uma nova. Se não houver outro, a TI desativa os gestores (`UPDATE usuarios SET ativo=0 WHERE perfil='gestor'`) e o painel volta a oferecer a "Primeira configuração", que exige o `token`. Usando o mesmo usuário, o cadastro é reativado com a nova senha.

## Se algo falhar

| Sintoma | Causa provável |
|---|---|
| `config.php ausente` | arquivo não copiado/renomeado |
| `banco indisponível` | `db_host`, `db_user`, `db_pass`; o detalhe do erro fica no log de erros do PHP |
| login sempre "sessão ausente ou expirada" | `cookie_seguro => true` com acesso por `http://` (o navegador não devolve o cookie) |
| `token anti-CSRF ausente` | página aberta antes de uma atualização: recarregue (Ctrl+F5) |
| `origem da requisição não autorizada` | painel aberto por outro host; deixe painel e API no mesmo endereço ou preencha `cors_origin` |
| `não foi possível criar a pasta de fotos` | `uploads/` sem permissão de escrita |
| fonte ou gráfico não aparecem | a pasta `vendor/` não foi publicada |
| 500 sem mensagem | PHP sem `pdo_mysql`, ou versão anterior à 7.4 |

O guia completo (arquitetura, matriz de acesso, Nginx e IIS, migração, consultas SQL e referências) está em `README_IMPLANTACAO.md`.

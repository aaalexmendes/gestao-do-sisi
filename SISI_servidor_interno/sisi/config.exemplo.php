<?php
/* =====================================================================
   Painel Unificado SISI · configuração do servidor interno (API 2.0)
   Copie para config.php e preencha. Este arquivo NÃO pode ser lido pelo
   navegador: o .htaccess (Apache) e o web.config (IIS) já bloqueiam; no
   Nginx use o trecho de servidores/nginx_sisi.conf. Melhor ainda: deixe o
   config.php fora da pasta pública e aponte para ele com a variável de
   ambiente SISI_CONFIG (ver README_IMPLANTACAO.md).
   Permissões sugeridas (Linux): chown root:www-data config.php; chmod 640.
   ===================================================================== */
return [
  /* ---------- Banco de dados ---------- */
  'db_host' => 'localhost',
  'db_port' => 3306,
  'db_name' => 'sisi',
  'db_user' => 'sisi_app',            // usuário com SELECT, INSERT, UPDATE, DELETE só no banco sisi
  'db_pass' => 'TROQUE-ESTA-SENHA',

  /* ---------- Token de instalação ----------
     Usado UMA vez, na tela "Primeira configuração", para criar o gestor inicial
     quando ainda não existe nenhum gestor no banco. Não é usado pelo painel no
     dia a dia (o acesso é por usuário e senha). Gere um valor longo:
       openssl rand -hex 24          (Linux)
       [guid]::NewGuid()             (PowerShell; use dois e junte)
     Depois da instalação, pode ser trocado por outro valor aleatório. */
  'token' => 'TROQUE-ESTE-TOKEN',

  /* ---------- Sessão ---------- */
  'sessao_horas'  => 8,               // validade absoluta da sessão (decisão do gestor: 8 h)
  'cookie_nome'   => 'SISI_SESS',
  // true quando o painel for acessado por https:// (o cookie só trafega cifrado).
  // Com http:// simples, deixe false — ou o navegador não devolverá o cookie.
  'cookie_seguro' => false,

  /* ---------- Senhas e tentativas de login ---------- */
  'senha_min'        => 12,           // mínimo de caracteres da senha escolhida pelo usuário (decisão do gestor)
  'login_max_falhas' => 5,            // falhas seguidas por login dentro da janela → bloqueio temporário
  'ip_max_falhas'    => 30,           // falhas por IP dentro da janela → bloqueio temporário do IP
  'login_janela_min' => 15,           // janela (minutos) para as duas contagens acima

  /* ---------- Rede ----------
     O painel e a api.php ficam no MESMO endereço; nesse caso deixe vazio
     (nenhum cabeçalho CORS é enviado, que é o mais seguro).
     Só preencha se o painel for aberto de OUTRO host, informando a origem
     exata, ex.: 'https://intranet.om.intraer'. '*' não é aceito (o acesso
     usa cookie de sessão, e o navegador recusa '*' com credenciais). */
  'cors_origin' => '',
  // Atrás de um proxy reverso que informe o IP real em X-Forwarded-For, liste o
  // IP do proxy aqui (ex.: ['10.0.0.5']). Vazio = usa o IP da conexão.
  'proxies_confiaveis' => [],

  /* ---------- Arquivos ---------- */
  // Pasta das fotos. Recomendado: FORA da pasta pública (ex.: '/var/lib/sisi/uploads'
  // ou 'D:\\sisi_dados\\uploads'). Precisa de permissão de escrita para o servidor web.
  'uploads'  => __DIR__ . '/uploads',
  'max_mb'   => 64,                   // tamanho máximo de um envio (JSON + fotos em base64)
  'foto_max_mb' => 10,                // tamanho máximo de CADA foto
  'hist_max' => 30,                   // envios anteriores de cada OM guardados em pacotes_hist (0 = todos)
];

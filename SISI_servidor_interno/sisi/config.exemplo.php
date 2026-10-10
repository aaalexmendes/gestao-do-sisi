<?php
/* =====================================================================
   Painel Unificado SISI · configuração do servidor interno
   Preencha e renomeie para config.php (este arquivo NÃO deve ser lido
   pelo navegador: o .htaccess bloqueia; se o servidor for Nginx/IIS,
   bloqueie manualmente ou mova-o para fora da pasta pública).
   ===================================================================== */
return [
  // Banco de dados (os mesmos dados que você usa no phpMyAdmin)
  'db_host' => 'localhost',
  'db_port' => 3306,
  'db_name' => 'sisi',
  'db_user' => 'sisi_app',
  'db_pass' => 'TROQUE-ESTA-SENHA',

  // Token de escrita: o painel envia este valor em toda gravação
  // (campo "token" do pacote, cabeçalho X-Token ou parâmetro ?token=).
  // Gere algo longo e aleatório, ex.: openssl rand -hex 24
  'token'   => 'TROQUE-ESTE-TOKEN',

  // Token de leitura do estado consolidado (credenciais, avisos...).
  // Pode ser igual ao de escrita; se vazio, qualquer um na rede lê o estado.
  'token_leitura' => '',

  // Pasta das fotos (relativa a este arquivo). Precisa ter permissão de escrita.
  'uploads' => __DIR__ . '/uploads',

  // Origens autorizadas (CORS). Como o painel é um arquivo HTML que pode ser
  // aberto de qualquer lugar da intranet, '*' é o mais prático. Restrinja se
  // o painel for servido por um host fixo, ex.: 'http://intranet.om.fab.mil.br'.
  'cors_origin' => '*',

  // Tamanho máximo de um pacote (JSON + fotos em base64), em MB.
  'max_mb' => 64,

  // Quantos envios anteriores de cada OM manter em pacotes_hist (0 = todos).
  'hist_max' => 30,
];

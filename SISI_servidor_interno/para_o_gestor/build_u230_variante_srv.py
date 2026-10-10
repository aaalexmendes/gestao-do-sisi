# -*- coding: utf-8 -*-
"""build_u230_variante_srv.py — Painel Unificado SISI u2.30-srv (variante servidor interno)

Gera a variante para o servidor interno (MySQL/PHP) a partir da LINHA PRINCIPAL
(index.html do GitHub, u2.30), sem alterar o original. Cada alteração é um patch
por âncora: se a linha principal mudar e uma âncora sumir, o script PARA e diz
qual — nada é gerado pela metade.

Uso (na pasta para_o_gestor/):
    python3 build_u230_variante_srv.py  [origem.html]  [destino.html]
Padrões: origem = ../../index.html · destino = ../sisi/index.html
Também atualiza o rol inicial de OMs em ../sisi/schema.sql (ROSTER_EMBED do painel).

O que muda em relação à u2.30 (pedidos da TI, 10/10/2026):
 1. Inicialização: servido por http(s), o painel usa SOZINHO a ./api.php da mesma
    origem (nenhuma configuração por navegador). Sem token compartilhado: login por
    usuário e senha na API, sessão por cookie HttpOnly e token anti-CSRF em memória.
 2. Controle de acesso: a senha é conferida no servidor; troca obrigatória no 1º
    acesso; o cadastro de usuários da Área Admin grava direto na API (rota=usuarios).
 3. Fotos dos registros exibidas pela api.php (rota=foto), com sessão.
 4. Bibliotecas e fontes locais (vendor/), sem acesso à internet.
 + Migração única do estado e dos usuários do JSONBin (ou de um arquivo .json).
"""
import io, json, os, re, sys

AQUI = os.path.dirname(os.path.abspath(__file__))
SRC = sys.argv[1] if len(sys.argv) > 1 else os.path.join(AQUI, '..', '..', 'index.html')
DST = sys.argv[2] if len(sys.argv) > 2 else os.path.join(AQUI, '..', 'sisi', 'index.html')
SCHEMA = os.path.join(AQUI, '..', 'sisi', 'schema.sql')
VERSAO_ORIG = 'u2.30 &#183; 05/10/2026'
VERSAO_NOVA = 'u2.30-srv &#183; 10/10/2026'

s = io.open(SRC, encoding='utf-8').read()
n = 0
def patch(old, new, tag, count=1):
    """Substitui exatamente `count` ocorrências de `old`; aborta se a âncora mudou."""
    global s, n
    c = s.count(old)
    if c != count:
        sys.exit(f'[ERRO] âncora "{tag}": encontrada {c}x (esperado {count}x). A linha principal mudou — revise o patch.')
    s = s.replace(old, new); n += 1; print('  ok ', tag)

if VERSAO_ORIG not in s:
    sys.exit(f'[ERRO] o arquivo de origem não é a u2.30 (carimbo "{VERSAO_ORIG}" não encontrado).')

# =====================================================================
# P4 · bibliotecas e fontes locais (vendor/)
# =====================================================================
patch('''<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@300;400;500;600;700&family=Roboto:wght@300;400;500;700&family=Roboto+Mono:wght@400;500;600&display=swap" rel="stylesheet">''',
'''<!-- u2.30-srv · fontes Roboto servidas localmente (vendor/fonts) — sem acesso à internet -->
<link href="vendor/fonts/fonts.css" rel="stylesheet">''', 'P4: fontes locais')
patch('''<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/papaparse@5.4.1/papaparse.min.js" defer></script>''',
'''<script src="vendor/xlsx-0.18.5/xlsx.full.min.js" defer></script>
<script src="vendor/papaparse-5.4.1/papaparse.min.js" defer></script>''', 'P4: xlsx + papaparse locais')
patch('''<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>''',
'''<script src="vendor/chart.js-4.4.1/chart.umd.js" defer></script>''', 'P4: chart.js local')
patch("""    sc.src='https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';""",
"""    sc.src='vendor/html2canvas-1.4.1/html2canvas.min.js';""", 'P4: html2canvas local')
patch("""    sc.src='https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js';""",
"""    sc.src='vendor/jspdf-2.5.1/jspdf.umd.min.js';""", 'P4: jspdf local')
patch("""verifique o acesso à internet e tente novamente""", """recarregue a página e tente novamente (se persistir, avise a TI: pasta vendor/)""", 'P4: mensagens de falha', count=2)

# =====================================================================
# P1 · runtime do servidor interno (antes de qualquer sincronização)
# =====================================================================
patch("""const DATA = {""",
r"""// ==================== u2.30-srv · SERVIDOR INTERNO (MySQL/PHP) ====================
/* Servido por http(s), o painel usa a api.php da MESMA origem, sem configuração.
   A Área Admin pode sobrescrever o endereço (caso raro). Não há token no navegador:
   o login abre uma sessão por cookie HttpOnly (o JavaScript não lê o cookie) e a API
   devolve um token anti-CSRF, guardado só nesta aba (sessionStorage), que o fetch
   abaixo envia em toda gravação. */
window.SRV = (function(){
  const K='sisi_srv_v1', KS='sisi_srv_csrf';
  function auto(){ try{ if(/^https?:$/.test(location.protocol)) return new URL('api.php', location.href).href; }catch(e){} return ''; }
  function get(){
    try{ const o=JSON.parse(localStorage.getItem(K)||'null'); if(o && o.url) return o; }catch(e){}
    const a=auto(); return a? {url:a, auto:true} : null;
  }
  function set(u){
    u=String(u||'').trim();
    try{ if(!u) localStorage.removeItem(K); else localStorage.setItem(K, JSON.stringify({url:u, salvoEm:new Date().toISOString()})); }catch(e){}
    return get();
  }
  function ativo(){ return !!get(); }
  function base(){ const o=get(); return o? o.url : ''; }
  function url(qs){ const b=base(); if(!b) return ''; return b+(qs? ((b.indexOf('?')>=0?'&':'?')+qs) : ''); }
  let csrf=''; try{ csrf=sessionStorage.getItem(KS)||''; }catch(e){}
  function setCsrf(c){ csrf=c||''; try{ if(csrf) sessionStorage.setItem(KS,csrf); else sessionStorage.removeItem(KS); }catch(e){} }
  function ehApi(u){
    const b=base(); if(!b||!u) return false;
    try{ const x=new URL(u, location.href), y=new URL(b, location.href); return x.origin===y.origin && x.pathname===y.pathname; }catch(e){ return false; }
  }
  function headers(extra){ return Object.assign({}, extra||{}); }   // a autenticação vai no cookie
  // fetch: cookie de sessão + token anti-CSRF em toda chamada à api.php; 401 = sessão vencida
  const _fetch = (typeof window.fetch==='function')? window.fetch.bind(window) : null;
  if(_fetch){
    window.fetch = function(input, init){
      let u=''; try{ u=(typeof input==='string')? input : ((input&&input.url)||''); }catch(e){}
      if(!ehApi(u)) return _fetch(input, init);
      init=Object.assign({}, init||{});
      init.credentials='include';
      const m=String(init.method||'GET').toUpperCase();
      if(m!=='GET' && m!=='HEAD'){ const h=new Headers(init.headers||{}); if(csrf) h.set('X-CSRF-Token', csrf); init.headers=h; }
      return _fetch(input, init).then(function(r){
        if(r.status===401 && !/[?&]rota=(login|sessao|instalar|logout)(&|$)/.test(u)){ try{ if(window.__srvSessaoExpirada) window.__srvSessaoExpirada(); }catch(e){} }
        return r;
      });
    };
  }
  async function call(qs, opts){
    opts=opts||{};
    const init={ method:opts.method||'GET', cache:'no-store', headers:{} };
    if(opts.body!==undefined){ init.headers['Content-Type']='application/json'; init.body=JSON.stringify(opts.body); }
    const r=await window.fetch(url(qs), init);
    let j=null; try{ j=await r.json(); }catch(e){}
    if(!r.ok || !j || j.ok===false){
      const e=new Error((j&&j.erro)||('HTTP '+r.status+' — a api.php não respondeu como esperado'));
      e.status=r.status; e.codigo=(j&&j.codigo)||''; e.dados=j; throw e;
    }
    return j;
  }
  async function ping(){ return call('ping=1'); }
  async function sessao(){ const j=await call('rota=sessao'); setCsrf(j.logado? j.csrf : ''); return j; }
  async function login(l, s){ const j=await call('rota=login', {method:'POST', body:{login:l, senha:s}}); setCsrf(j.csrf); return j; }
  async function logout(){ try{ await call('rota=logout', {method:'POST', body:{}}); }catch(e){} setCsrf(''); }
  async function trocarSenha(atual, nova){ return call('rota=senha', {method:'POST', body:{atual:atual, nova:nova}}); }
  async function instalar(d){ const j=await call('rota=instalar', {method:'POST', body:d}); setCsrf(j.csrf); return j; }
  async function usuarios(){ return call('rota=usuarios'); }
  async function usuariosPost(b){ return call('rota=usuarios', {method:'POST', body:b}); }
  return { get, set, ativo, base, url, headers, ping, sessao, login, logout, trocarSenha, instalar, usuarios, usuariosPost, call };
})();
const DATA = {""", 'P1: runtime SRV (detecção, sessão, fetch com CSRF)')

# =====================================================================
# Estado do gestor: JSONBin → api.php?rota=estado
# =====================================================================
patch("""  function getCfg(){
    return {""",
"""  // u2.30-srv — com o servidor interno, o "bin" é a rota=estado da api.php
  function binGetUrl(cfg){ return SRV.ativo()? SRV.url('rota=estado') : `https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}/latest`; }
  function binPutUrl(cfg){ return SRV.ativo()? SRV.url('rota=estado') : `https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}`; }
  function binPutMethod(){ return SRV.ativo()? 'POST' : 'PUT'; }
  function binHeaders(cfg){ return SRV.ativo()? SRV.headers() : keyHeader(cfg); }
  function binPronto(cfg){ return SRV.ativo() || !!cfg.binId; }
  function rotPub(){ return SRV.ativo()? 'Enviar dados ao servidor' : 'Enviar dados ao JSONBin'; }
  function getCfg(){
    return {""", 'estado: roteamento condicional')
patch("""    if(!cfg.binId){ if(!silent){ setBinStatus('warn','Informe o Bin ID para carregar.'); } return null; }
    try{
      const resp=await fetch(`https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}/latest`,
        { method:'GET', cache:'no-store', headers: Object.assign({}, keyHeader(cfg)) });""",
"""    if(!binPronto(cfg)){ if(!silent){ setBinStatus('warn','Informe o Bin ID (ou configure o servidor interno) para carregar.'); } return null; }
    try{
      const resp=await fetch(binGetUrl(cfg),
        { method:'GET', cache:'no-store', headers: Object.assign({}, binHeaders(cfg)) });""", 'loadFromBin')
patch("""    if(!cfg.binId){ setBinStatus('warn','Informe o Bin ID antes de testar.'); return; }
    setBinStatus('','Testando conexão…');
    try{
      const resp=await fetch(`https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}/latest`,
        { method:'GET', cache:'no-store', headers: Object.assign({}, keyHeader(cfg)) });""",
"""    if(!binPronto(cfg)){ setBinStatus('warn','Informe o Bin ID (ou configure o servidor interno) antes de testar.'); return; }
    setBinStatus('','Testando conexão…');
    try{
      const resp=await fetch(binGetUrl(cfg),
        { method:'GET', cache:'no-store', headers: Object.assign({}, binHeaders(cfg)) });""", 'testConnection')
patch("""    if(!cfg.binId || !cfg.key){ setBinStatus('warn','Para enviar é preciso Bin ID e uma chave com permissão de escrita.'); return; }""",
"""    if(!SRV.ativo() && (!cfg.binId || !cfg.key)){ setBinStatus('warn','Para enviar é preciso Bin ID e uma chave com permissão de escrita.'); return; }""", 'publish: pré-condição')
patch("""      const resp=await fetch(`https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}`,
        { method:'PUT', headers: Object.assign({'Content-Type':'application/json'}, keyHeader(cfg)), body: JSON.stringify(payload) });""",
"""      const resp=await fetch(binPutUrl(cfg),
        { method:binPutMethod(), headers: Object.assign({'Content-Type':'application/json'}, binHeaders(cfg)), body: JSON.stringify(payload) });""", 'publish: envio')
patch("""    let planGz=null;
    if(plan){""",
"""    let planGz=null;
    const comprimir = !SRV.ativo();   // o MySQL não tem o limite de 100 KB do jsonbin
    if(plan && comprimir){""", 'publish: compressão só no jsonbin')
patch("""    const execGz = exec? await gzipJson(exec) : null;
    const prevGz = execPrevRaw? await gzipJson(execPrevRaw) : null;
    const przGz = prazos? await gzipJson(prazos) : null;""",
"""    const execGz = (exec && comprimir)? await gzipJson(exec) : null;
    const prevGz = (execPrevRaw && comprimir)? await gzipJson(execPrevRaw) : null;
    const przGz = (prazos && comprimir)? await gzipJson(prazos) : null;""", 'publish: compressão condicional')
patch("""btn.textContent='Enviar dados ao JSONBin'""", """btn.textContent=rotPub()""", 'publish: rótulo do botão', count=2)
patch("""      blog('Dados enviados ao bin: '+bits.join(' + ')+'.','ok');""",
"""      blog('Dados enviados ao bin: '+bits.join(' + ')+'.','ok');
      window.__binUltimoEnvioOk=true;""", 'publish: sinaliza sucesso')
patch(""", totalKB>100?'err':'info');""", """, (totalKB>100 && !SRV.ativo())?'err':'info');""", 'publish: limite de 100 KB só no jsonbin')
patch("""      setSourceTag('dados via JSONBin ('+parts.join(' + ')+')');""",
"""      setSourceTag((SRV.ativo()? 'dados via servidor interno (' : 'dados via JSONBin (')+parts.join(' + ')+')');""", 'rótulo da origem dos dados')
# Carga do estado: no servidor interno, só DEPOIS do login (a leitura exige sessão);
# migração única do JSONBin/arquivo para o servidor.
patch("""  window.__binTest={ gzipJson, gunzipJson, applyRemote };
  if(typeof fetch!=='undefined' && getCfg().binId){ loadFromBin(true).finally(()=>{ window.__authLoaded=true; }); } else { window.__authLoaded=true; }""",
r"""  window.__binTest={ gzipJson, gunzipJson, applyRemote };
  window.__binLoad = loadFromBin;
  /* u2.30-srv — migração única: lê o estado do JSONBin (ou de um arquivo .json exportado
     dele), aplica neste navegador, grava no servidor e importa os usuários. */
  window.__srvMigrar = async function(textoArquivo, aviso){
    const diz = aviso || function(){};
    let data;
    if(textoArquivo){
      diz('Lendo o arquivo…');
      const j=JSON.parse(textoArquivo); data=(j && j.record && typeof j.record==='object')? j.record : j;
    } else {
      const cfg=getCfg();
      if(!cfg.binId) throw new Error('informe o Bin ID no cartão "Compartilhamento · JSONBin" abaixo');
      diz('Baixando o estado do JSONBin…');
      const r=await fetch(`https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}/latest`, { method:'GET', cache:'no-store', headers:Object.assign({}, keyHeader(cfg)) });
      if(!r.ok) throw new Error('JSONBin respondeu HTTP '+r.status+(r.status===401?' — informe a chave (Access/Master Key) no cartão do JSONBin':''));
      const j=await r.json(); data=j.record||j;
    }
    if(!data || typeof data!=='object') throw new Error('conteúdo não reconhecido');
    const credenciais=(data.auth && Array.isArray(data.auth.credenciais))? data.auth.credenciais : [];
    const semAuth=Object.assign({}, data); delete semAuth.auth; delete semAuth.gas;
    diz('Aplicando neste navegador…');
    await applyRemote(semAuth, 'migração');
    diz('Gravando o estado no servidor…');
    window.__binUltimoEnvioOk=false;
    await publish();
    if(!window.__binUltimoEnvioOk) throw new Error('o servidor não aceitou o estado — veja o Registro no fim da Área Admin');
    let imp=null;
    if(credenciais.length){
      diz('Importando '+credenciais.length+' usuário(s)…');
      imp=await SRV.usuariosPost({acao:'importar', credenciais:credenciais});
      try{ if(window.__srvUsuariosAplicar) window.__srvUsuariosAplicar(imp); }catch(e){}
    }
    return { usuarios: imp? imp.importacao : [], temEstado: !!(data.exec2026||data.exec2026_gz||data.msgs||data.prazos2026||data.prazos2026_gz) };
  };
  if(SRV.ativo()){
    window.__authLoaded=true;                  // credenciais ficam no servidor
    try{ const h=document.querySelector('#adminBinPublishBtn'); if(h) h.textContent=rotPub(); }catch(e){}
  } else if(typeof fetch!=='undefined' && getCfg().binId){ loadFromBin(true).finally(()=>{ window.__authLoaded=true; }); } else { window.__authLoaded=true; }""",
'estado: carga após o login + migração')

# =====================================================================
# Cartão "Servidor interno" na Área Admin (aba Sincronização)
# =====================================================================
patch("""        <div class="admin-section" data-adm="sync">
          <h3>Compartilhamento · JSONBin</h3>""",
"""        <div class="admin-section" data-adm="sync">
          <h3>Servidor interno &#183; MySQL / PHP</h3>
          <p>Aberto pelo endere&#231;o da intranet, o painel usa sozinho a <strong>api.php</strong> da mesma pasta: estado do gestor,
            pacotes das OMs, fotos, auditoria, ci&#234;ncias e usu&#225;rios ficam no banco interno. Nada precisa ser configurado nos navegadores.
            O campo abaixo s&#243; &#233; necess&#225;rio se a TI publicar a API em outro caminho.</p>
          <div class="admin-config-status" id="srvStatus">&#8212;</div>
          <div class="admin-config-row" style="grid-template-columns:1fr;">
            <label><span>Endere&#231;o da api.php (opcional)</span><input type="url" id="srvUrl" placeholder="em branco = autom&#225;tico (./api.php)" autocomplete="off"></label>
          </div>
          <div class="admin-preview-actions" style="border-top:none;padding-top:8px;flex-wrap:wrap;">
            <button class="admin-btn admin-btn-success" id="srvSalvarBtn" type="button">Salvar e testar</button>
            <button class="admin-btn admin-btn-secondary" id="srvLimparBtn" type="button">Voltar ao autom&#225;tico</button>
          </div>
        </div>
        <div class="admin-section" data-adm="sync">
          <h3>Migra&#231;&#227;o dos dados atuais (uma vez)</h3>
          <p>Traz do painel antigo o <strong>estado do gestor</strong> (execu&#231;&#227;o, prazos, RaP, planejamento, avisos, janela anual) e os
            <strong>usu&#225;rios</strong>. Cada usu&#225;rio entra com o <strong>login mostrado abaixo</strong> e a senha que j&#225; tinha, e o painel
            o obriga a criar uma senha nova nesse primeiro acesso. Os lan&#231;amentos das OMs chegam quando cada OM clicar
            <strong>Enviar ao servidor</strong> uma vez. Use o JSONBin (precisa de internet neste computador) ou um arquivo .json
            salvo de <code>https://api.jsonbin.io/v3/b/&lt;Bin ID&gt;/latest</code>.</p>
          <div class="admin-preview-actions" style="border-top:none;padding-top:0;flex-wrap:wrap;">
            <button class="admin-btn admin-btn-success" id="srvMigJsonbinBtn" type="button">Importar do JSONBin</button>
            <label class="admin-btn admin-btn-secondary" style="cursor:pointer">Importar de arquivo .json<input type="file" id="srvMigArquivo" accept=".json,application/json" style="display:none"></label>
          </div>
          <div class="admin-config-status" id="srvMigStatus">&#8212;</div>
          <div id="srvMigLista"></div>
        </div>
        <div class="admin-section" data-adm="sync">
          <h3>Compartilhamento · JSONBin</h3>""", 'HTML: cartões servidor interno + migração')
patch("""  $('adminClear2026Btn').addEventListener('click',clear2026);""",
r"""  $('adminClear2026Btn').addEventListener('click',clear2026);
  /* u2.30-srv — servidor interno e migração */
  function srvRender(){ const o=SRV.get(); const st=$('srvStatus'); if(!st) return;
    if($('srvUrl')) $('srvUrl').value=(o&&!o.auto)?o.url:'';
    st.className='admin-config-status'+(o?' ok':' warn');
    st.textContent=!o? 'Painel aberto fora de um servidor web (arquivo local): servidor interno inativo.'
      : (o.auto? 'Ativo (automático): '+o.url : 'Ativo (endereço manual): '+o.url); }
  async function srvTestar(){ const st=$('srvStatus');
    try{ const j=await SRV.ping(); st.className='admin-config-status ok'; st.textContent='Conexão OK · '+(j.versao||'')+' · '+SRV.base(); }
    catch(e){ st.className='admin-config-status err'; st.textContent='A api.php não respondeu: '+e.message; } }
  $('srvSalvarBtn').addEventListener('click',async()=>{
    const url=$('srvUrl').value.trim();
    if(url && !/^https?:\/\/.+\/api\.php$/i.test(url)){ const st=$('srvStatus'); st.className='admin-config-status warn'; st.textContent='O endereço deve terminar em /api.php (ex.: https://intranet/sisi/api.php).'; return; }
    SRV.set(url); srvRender(); await srvTestar(); alog('Servidor interno: '+SRV.base(),'ok');
  });
  $('srvLimparBtn').addEventListener('click',async()=>{ SRV.set(''); srvRender(); await srvTestar(); });
  function srvMigListar(lista){
    const box=$('srvMigLista'); if(!box) return;
    if(!lista||!lista.length){ box.innerHTML=''; return; }
    const e=x=>String(x==null?'':x).replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
    box.innerHTML='<table class="cred-table"><thead><tr><th>Usuário</th><th>Login</th><th>Acesso</th><th>Situação</th></tr></thead><tbody>'+
      lista.map(r=>'<tr><td>'+e(r.nome)+'</td><td><code>'+e(r.login||'—')+'</code></td><td>'+e(String(r.perfil||'').toUpperCase()+(r.escopo?' · '+r.escopo:''))+'</td><td>'+e(r.situacao)+'</td></tr>').join('')+
      '</tbody></table><div class="cred-hint">Informe a cada usuário o seu login (a senha continua a mesma até o primeiro acesso). A lista completa está na aba Acessos e usuários.</div>';
  }
  async function srvMigrar(texto){
    const st=$('srvMigStatus'); const diz=t=>{ st.className='admin-config-status'; st.textContent=t; };
    if(!confirm('Importar o estado e os usuários do painel antigo para o servidor interno?\nO estado atual do servidor será substituído; usuários já existentes não são duplicados.')) return;
    try{ const r=await window.__srvMigrar(texto, diz);
      st.className='admin-config-status ok';
      st.textContent='Migração concluída: '+(r.temEstado?'estado gravado no servidor':'estado sem dados de execução')+' · '+r.usuarios.filter(x=>x.situacao==='importado').length+' usuário(s) importado(s).';
      srvMigListar(r.usuarios); alog('Migração para o servidor interno concluída.','ok');
    }catch(e){ st.className='admin-config-status err'; st.textContent='Falha na migração: '+e.message; }
  }
  $('srvMigJsonbinBtn').addEventListener('click',()=>srvMigrar(null));
  $('srvMigArquivo').addEventListener('change',e=>{ const f=e.target.files&&e.target.files[0]; if(!f) return;
    const rd=new FileReader(); rd.onload=()=>srvMigrar(String(rd.result||'')); rd.readAsText(f,'utf-8'); e.target.value=''; });
  srvRender();
  if(SRV.ativo()){ try{ const h=document.querySelector('#adminBinPublishBtn'); if(h){ const sec=h.closest('.admin-section'); const t=sec&&sec.querySelector('h3'); if(t) t.textContent='Compartilhamento · estado do gestor (servidor interno)'; } }catch(e){} }""",
'admin: ações dos cartões')

# =====================================================================
# Pacotes das OMs (ex-Apps Script) e API ?om= / ?all= / ?roster=
# =====================================================================
patch("""function getGas(){
  try{ var v=JSON.parse(localStorage.getItem(LS_GAS)||'null');""",
"""function getGas(){
  /* u2.30-srv — com o servidor interno, o "Apps Script" é a api.php (sem token: sessão) */
  if(window.SRV && SRV.ativo()) return { url:SRV.base(), token:'', liberado:true, srv:true, updatedAt:null };
  try{ var v=JSON.parse(localStorage.getItem(LS_GAS)||'null');""", 'pacotes: getGas → api.php')
patch("""window.__gasForPayload=function(){ var c=getGas(); return c.url? c : null; };""",
"""window.__gasForPayload=function(){ var c=getGas(); return (c.url && !c.srv)? c : null; };""", 'pacotes: não publica a URL interna no estado')
patch("""let cfg = Object.assign({apiUrl:'', senhaLocal:'sisi2026', senhaSessao:''}, lsGet(LS.cfg,{}));""",
"""let cfg = Object.assign({apiUrl:'', senhaLocal:'sisi2026', senhaSessao:''}, lsGet(LS.cfg,{}));
if(window.SRV && SRV.ativo()) cfg.apiUrl = SRV.base();   // u2.30-srv — contrato ?om= / ?all= / ?roster=""", 'PM: apiUrl = api.php')
patch("""  _audit:auditPush, _mergeAudit:mergeAuditoria,""",
"""  _audit:auditPush, _mergeAudit:mergeAuditoria, _setApiUrl:(u)=>{ cfg.apiUrl=u||''; lsSet(LS.cfg,cfg); try{ renderCtx(); }catch(e){} },""", 'PM._setApiUrl')

# =====================================================================
# P3 · fotos: exibidas pela api.php (rota=foto) quando o registro só tem a referência
# =====================================================================
patch("""function regCardHtml(r, readonly, ctx){""",
"""/* u2.30-srv — foto do registro: a imagem local (dataUrl) ou, se já foi enviada,
   a cópia do servidor (rota=foto: a API confere a sessão e o escopo). */
function fotoSrc(f, sigla){
  if(f && f.dataUrl) return f.dataUrl;
  if(f && f.ref && window.SRV && SRV.ativo() && sigla) return SRV.url('rota=foto&om='+encodeURIComponent(sigla)+'&nome='+encodeURIComponent(f.ref));
  return '';
}
function regCardHtml(r, readonly, ctx){""", 'P3: helper fotoSrc')
patch("""(r.fotos&&r.fotos.length?'<div class="reg-fotos">'+r.fotos.map((f,i)=>'<img src="'+f.dataUrl+'" alt="foto" data-foto="1">').join('')+'</div>':'')+""",
"""(r.fotos&&r.fotos.length?'<div class="reg-fotos">'+r.fotos.map((f,i)=>'<img src="'+esc(fotoSrc(f, ((ctx||omData||{}).om)||(perfil&&perfil.om)||''))+'" alt="foto" data-foto="1" loading="lazy">').join('')+'</div>':'')+""",
'P3: foto no cartão do registro')
patch("""    const fts=(r.fotos||[]).map(f=>'<img src=\\"'+(f.dataUrl||'')+'\\">').join('');""",
"""    const fts=(r.fotos||[]).map(f=>'<img src=\\"'+esc(fotoSrc(f, sigla))+'\\">').join('');""", 'P3: foto no relatório HTML')

# =====================================================================
# P2 · login, sessão e usuários no servidor (camada unificada)
# =====================================================================
patch("""  <label>Senha</label>
  <input type="password" id="entSenhaU" autocomplete="current-password">""",
"""  <div id="entLoginWrap" style="display:none"><label for="entLoginU">Usu&#225;rio</label>
  <input type="text" id="entLoginU" autocomplete="username" autocapitalize="none" spellcheck="false"></div>
  <label>Senha</label>
  <input type="password" id="entSenhaU" autocomplete="current-password">
  <div id="entSrvPainel"></div>""", 'HTML: campo Usuário na entrada')
patch("""<button id="sessBarSair" type="button">Sair</button></div>""",
"""<button id="sessBarSenha" type="button" style="display:none;margin-right:8px">Trocar senha</button><button id="sessBarSair" type="button">Sair</button></div>""", 'HTML: botão Trocar senha')
patch("""            <input type="email" id="credEmail" placeholder="E-mail" style="flex:2 1 220px">""",
"""            <input type="email" id="credEmail" placeholder="E-mail" style="flex:2 1 220px">
            <input type="text" id="credLogin" placeholder="Usuário (login) — em branco: e-mail ou gerado" autocomplete="off" autocapitalize="none" spellcheck="false" style="flex:1 1 220px;display:none">""", 'HTML: campo login no cadastro')

patch("""function authGet(){
  try{ var v=JSON.parse(localStorage.getItem(LS_AUTH)||'null');""",
"""function authGet(){
  /* u2.30-srv — lista vinda da api.php (rota=usuarios, só gestor); sem hashes */
  if(window.SRV && SRV.ativo()) return { credenciais:(window.__srvUsers||[]), updatedAt:null };
  try{ var v=JSON.parse(localStorage.getItem(LS_AUTH)||'null');""", 'auth: authGet do servidor')
patch("""function authSet(store){
  store.updatedAt=new Date().toISOString();""",
"""function authSet(store){
  if(window.SRV && SRV.ativo()){ srvSyncUsuarios(store); return; }
  store.updatedAt=new Date().toISOString();""", 'auth: authSet grava no servidor')
patch("""window.__authForPayload = function(){
  var a=authGet();""",
"""window.__authForPayload = function(){
  if(window.SRV && SRV.ativo()) return null;      // credenciais nunca viajam no estado
  var a=authGet();""", 'auth: estado sem credenciais')

patch("""async function entEntrar(){
  var msg=el('entMsg');""",
"""async function entEntrar(){
  if(window.SRV && SRV.ativo()) return srvEntrar();
  var msg=el('entMsg');""", 'login: conferido no servidor')
patch("""function bootstrapGestor(){
  if(!semCredenciais()){""",
"""function bootstrapGestor(){
  if(window.SRV && SRV.ativo()) return srvMostraInstalar();
  if(!semCredenciais()){""", 'instalação: gestor inicial no servidor')
patch("""window.__logoutUnificado = function(){
  try{ if(window.PM&&PM.sair) PM.sair(); }catch(e){}""",
"""window.__logoutUnificado = function(){
  if(window.SRV && SRV.ativo()){
    try{ SRV.logout(); }catch(e){}
    /* computador compartilhado: some o consolidado e a lista de usuários deste navegador
       (o rascunho não enviado da OM continua guardado para não perder trabalho) */
    try{ localStorage.removeItem('sisiPM_all'); localStorage.removeItem(LS_AUTH); }catch(e){}
    window.__srvUsers=[];
  }
  try{ if(window.PM&&PM.sair) PM.sair(); }catch(e){}""", 'logout: encerra a sessão no servidor')
patch("""  initAdmTabs();
  var saved=null;""",
"""  initAdmTabs();
  if(window.SRV && SRV.ativo()){ srvIniciar(); return; }   // u2.30-srv — a sessão é a do servidor
  var saved=null;""", 'início: sessão vem do servidor')

# cadastro de usuários: campo login + mensagens
patch("""           email:el('credEmail').value.trim(), perfil:el('credPerfil').value,""",
"""           email:el('credEmail').value.trim(), perfil:el('credPerfil').value,
           login:(el('credLogin')? el('credLogin').value.trim().toLowerCase() : ''),""", 'cadastro: lê o login')
patch("""  ['credNome','credPosto','credOmUser','credTel','credEmail','credSenha'].forEach(function(id){ el(id).value=''; });""",
"""  ['credNome','credPosto','credOmUser','credTel','credEmail','credSenha','credLogin'].forEach(function(id){ if(el(id)) el(id).value=''; });""", 'cadastro: limpa o login')
patch("""  el('credEmail').value=c.email||'';
  el('credPerfil').value=c.perfil||'om';""",
"""  el('credEmail').value=c.email||'';
  if(el('credLogin')) el('credLogin').value=c.login||'';
  el('credPerfil').value=c.perfil||'om';""", 'cadastro: edita o login')
patch("""    c.perfil=f.perfil; c.escopo=f.escopo||null; c.rotulo=rotulo;""",
"""    c.perfil=f.perfil; c.escopo=f.escopo||null; c.rotulo=rotulo; if(f.login) c.login=f.login;""", 'cadastro: grava o login (edição)')
patch("""    rotulo:rotulo, perfil:f.perfil, escopo:f.escopo||null,
    salt:salt, hash:sha256Hex(salt+f.senha), criadoEm:new Date().toISOString() };""",
"""    rotulo:rotulo, perfil:f.perfil, escopo:f.escopo||null, login:f.login||'',
    salt:salt, hash:sha256Hex(salt+f.senha), criadoEm:new Date().toISOString() };""", 'cadastro: grava o login (novo)')
patch("""    var contato=[c.tel,c.email].filter(Boolean).join('<br>');""",
"""    var contato=[c.tel,c.email].filter(Boolean).map(escU).join('<br>');   // u2.30-srv: dados vêm do servidor — escapados""", 'lista: escapa contato')
patch("""    return '<tr><td><strong>'+credUserLabel(c)+'</strong></td>'+
      '<td>'+(c.omUser||'\\u2014')+'</td>'+""",
"""    return '<tr><td><strong>'+escU(credUserLabel(c))+'</strong>'+srvCredInfo(c)+'</td>'+
      '<td>'+(c.omUser? escU(c.omUser) : '\\u2014')+'</td>'+""", 'lista: login, situação e escape')
patch("""      '<td>'+acesso+'</td>'+""", """      '<td>'+escU(acesso)+'</td>'+""", 'lista: escapa acesso')
patch("""Altera\\u00e7\\u00f5es s\\u00f3 valem nos demais navegadores ap\\u00f3s "Enviar dados ao JSONBin".</div>';""",
"""'+((window.SRV&&SRV.ativo())? 'Altera\\u00e7\\u00f5es gravadas direto no servidor.' : 'Altera\\u00e7\\u00f5es s\\u00f3 valem nos demais navegadores ap\\u00f3s "Enviar dados ao JSONBin".')+'</div>';""", 'lista: rodapé')
patch("""function credNote(t){ var n=el('credNote'); if(n) n.textContent=t; }""",
"""function credNote(t){
  if(window.SRV && SRV.ativo()) t=String(t||'').replace(/ ?(Use|Depois clique em) "Enviar dados ao JSONBin" para (propagar|ativar[^.]*)\\.?/g,'').replace(/JSONBin/g,'servidor');
  var n=el('credNote'); if(n) n.textContent=t; }""", 'mensagens: sem JSONBin')
patch("""    ' Depois clique em "Enviar dados ao JSONBin" para ativar a senha nos demais navegadores.';""",
"""    ((window.SRV&&SRV.ativo())? ' A senha já vale e é provisória: no primeiro acesso o usuário cria a dele.' : ' Depois clique em "Enviar dados ao JSONBin" para ativar a senha nos demais navegadores.');""", 'mensagens: senha provisória')
patch("""    .replace(/\\{LINK\\}/g, link)""",
"""    .replace(/\\{LINK\\}/g, link)
    .replace(/\\{LOGIN\\}/g, c.login||'')""", 'e-mail: marcador {LOGIN}')
patch("""  var subject='Acesso ao Painel de Gest\\u00e3o do Projeto SISI'""",
"""  if(window.SRV && SRV.ativo() && body.indexOf('Usuário: ')<0){
    body=body.replace(/\\nSenha: /, '\\nUsuário: '+(c.login||'(definido ao salvar)')+'\\nSenha: ')
             .replace(/(\\nSenha: [^\\n]*)/, '$1 (provisória — no primeiro acesso o painel pede que você crie a sua)');
  }
  var subject='Acesso ao Painel de Gest\\u00e3o do Projeto SISI'""", 'e-mail: login e senha provisória')

# ---------- bloco de funções do servidor (camada unificada) ----------
patch("""/* ---------- Primeira configuração (só quando não há credencial alguma) ---------- */""",
r"""/* =====================================================================
   u2.30-srv — LOGIN, SESSÃO E USUÁRIOS NO SERVIDOR INTERNO
   ===================================================================== */
var srvSenhaDigitada='';                       // só em memória, até a troca obrigatória
function srvMsg(t, ok){ var m=el('entMsg'); if(!m) return; m.style.color=ok?'#1d6b3a':''; m.textContent=t||''; }
function srvSessDe(u){
  var sess={ tipo:u.perfil, rotulo:u.rotulo||u.nome||u.login, uid:'srv'+u.id, nome:u.nome||'', posto:u.posto||'',
             login:u.login, em:new Date().toISOString(), srv:true };
  if(u.perfil==='gestor') sess.baseGestor=true;
  if(u.perfil==='ods') sess.ods=u.escopo;
  if(u.perfil==='om'){ sess.om=u.escopo; sess.omNome=resolveOmNome(u.escopo); }
  return sess;
}
function srvMostraLogin(){
  var lw=el('entLoginWrap'); if(lw) lw.style.display='';
  var sub=document.querySelector('#entryOverlay .uent-sub');
  if(sub) sub.textContent='Informe seu usuário e sua senha — o painel reconhece automaticamente o seu perfil (Gestor, ODS ou OM)';
  ['entSenhaU','entBtnEntrar'].forEach(function(id){ var e=el(id); if(e) e.style.display=''; });
  var lbl=el('entSenhaU') && el('entSenhaU').previousElementSibling; if(lbl && lbl.tagName==='LABEL') lbl.style.display='';
  var p=el('entSrvPainel'); if(p) p.innerHTML='';
  el('entryOverlay').style.display=''; document.body.classList.add('entry-locked');
  setTimeout(function(){ try{ (el('entLoginU').value? el('entSenhaU') : el('entLoginU')).focus(); }catch(e){} }, 50);
}
function srvEscondeLogin(){
  ['entLoginWrap','entSenhaU','entBtnEntrar','entBootstrap'].forEach(function(id){ var e=el(id); if(e) e.style.display='none'; });
  var lbl=el('entSenhaU') && el('entSenhaU').previousElementSibling; if(lbl && lbl.tagName==='LABEL') lbl.style.display='none';
}
async function srvIniciar(){
  try{ localStorage.removeItem(LS_SESS); }catch(e){}
  var lu=el('entLoginU');
  if(lu && !lu.__b){ lu.__b=1; lu.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); el('entSenhaU').focus(); } }); }
  var bs=el('sessBarSenha');
  if(bs && !bs.__b){ bs.__b=1; bs.style.display=''; bs.addEventListener('click',function(){ srvMostraTroca(null,{voluntaria:true}); }); }
  var cl=el('credLogin'); if(cl) cl.style.display='';
  srvMostraLogin(); srvMsg('Conectando ao servidor interno…', true);
  try{
    var j=await SRV.sessao();
    window.__srvPolitica=j.senhaMin||12;
    if(j.logado){ srvMsg(''); if(j.usuario.trocaObrigatoria) srvMostraTroca(j.usuario); else await srvAbreSessao(j.usuario); return; }
    srvMsg('');
    var bt=el('entBootstrap'); if(bt) bt.style.display = j.instalado? 'none' : '';
    if(!j.instalado) srvMsg('Servidor sem gestor cadastrado: use "Primeira configuração" abaixo (a TI informa o token de instalação).', true);
  }catch(e){
    srvMsg('Não foi possível falar com o servidor interno ('+e.message+'). Avise a TI.');
  }
}
async function srvEntrar(){
  var login=String(el('entLoginU').value||'').trim().toLowerCase(), senha=el('entSenhaU').value;
  if(!login || !senha){ srvMsg('Informe usuário e senha.'); return; }
  srvMsg('Verificando…', true);
  try{
    var j=await SRV.login(login, senha);
    el('entSenhaU').value='';
    window.__srvPolitica=j.senhaMin||window.__srvPolitica||12;
    if(j.usuario.trocaObrigatoria){ srvSenhaDigitada=senha; srvMostraTroca(j.usuario); return; }
    srvMsg(''); await srvAbreSessao(j.usuario);
  }catch(e){ el('entSenhaU').value=''; srvMsg(e.message); }
}
async function srvAbreSessao(u){
  var sess=srvSessDe(u);
  window.__srvUsuario=u;
  /* o estado (execução, prazos, avisos…) só pode ser lido depois do login */
  try{ if(window.__binLoad) await window.__binLoad(true); }catch(e){}
  configureProfile(sess);
  var bs=el('sessBarSenha'); if(bs) bs.style.display='';
  if(u.perfil==='gestor') srvCarregaUsuarios();
}
function srvMostraTroca(u, opt){
  opt=opt||{};
  var u2=u||window.__srvUsuario||{};
  var p=el('entSrvPainel'); if(!p) return;
  el('entryOverlay').style.display=''; document.body.classList.add('entry-locked');
  srvEscondeLogin();
  var min=window.__srvPolitica||12;
  var pedeAtual=!srvSenhaDigitada;
  p.innerHTML=
    '<div style="margin-top:6px;padding:10px 12px;background:#eef3f9;border-left:3px solid #2B3F53;font-size:12.5px;color:#2B3F53">'+
      (opt.voluntaria? 'Troca de senha' : '<strong>Crie a sua senha pessoal.</strong> A senha recebida era provisória e deixa de valer agora.')+
      '<br>Use pelo menos <strong>'+min+' caracteres</strong>. Uma frase com três ou quatro palavras é fácil de lembrar e difícil de adivinhar. Não use o seu nome, o seu usuário nem "sisi".</div>'+
    (pedeAtual? '<label for="entSenhaAtual">Senha atual</label><input type="password" id="entSenhaAtual" autocomplete="current-password">' : '')+
    '<label for="entSenhaNova">Nova senha</label><input type="password" id="entSenhaNova" autocomplete="new-password">'+
    '<label for="entSenhaNova2">Repita a nova senha</label><input type="password" id="entSenhaNova2" autocomplete="new-password">'+
    '<button type="button" id="entTrocarBtn" class="uent-go">Salvar nova senha</button>'+
    '<button type="button" id="entTrocarCancel" style="margin-top:8px;width:100%;background:none;border:1px solid #c8cdd4;padding:8px;cursor:pointer;font:12px Roboto,sans-serif;color:#5b6572">'+(opt.voluntaria?'Cancelar':'Sair')+'</button>';
  srvMsg('Usuário: '+(u2.login||'')+(u2.rotulo? ' · '+u2.rotulo : ''), true);
  var ir=async function(){
    var atual=pedeAtual? el('entSenhaAtual').value : srvSenhaDigitada;
    var nova=el('entSenhaNova').value, nova2=el('entSenhaNova2').value;
    if(!atual){ srvMsg('Informe a senha atual.'); return; }
    if(nova.length<min){ srvMsg('A nova senha precisa ter pelo menos '+min+' caracteres.'); return; }
    if(nova!==nova2){ srvMsg('As duas senhas novas não são iguais.'); return; }
    srvMsg('Gravando…', true);
    try{
      var j=await SRV.trocarSenha(atual, nova);
      srvSenhaDigitada='';
      p.innerHTML='';
      if(opt.voluntaria){ el('entryOverlay').style.display='none'; document.body.classList.remove('entry-locked'); srvMostraLoginCampos(); alert('Senha alterada.'); return; }
      srvMsg(''); srvMostraLoginCampos(); await srvAbreSessao(j.usuario);
    }catch(e){ srvMsg(e.message); }
  };
  el('entTrocarBtn').addEventListener('click', ir);
  el('entSenhaNova2').addEventListener('keydown', function(e){ if(e.key==='Enter') ir(); });
  el('entTrocarCancel').addEventListener('click', function(){
    p.innerHTML='';
    if(opt.voluntaria){ el('entryOverlay').style.display='none'; document.body.classList.remove('entry-locked'); srvMostraLoginCampos(); return; }
    srvSenhaDigitada=''; window.__logoutUnificado(); srvMostraLogin();
  });
  setTimeout(function(){ try{ (pedeAtual? el('entSenhaAtual') : el('entSenhaNova')).focus(); }catch(e){} }, 50);
}
function srvMostraLoginCampos(){
  ['entLoginWrap','entSenhaU','entBtnEntrar'].forEach(function(id){ var e=el(id); if(e) e.style.display=''; });
  var lbl=el('entSenhaU') && el('entSenhaU').previousElementSibling; if(lbl && lbl.tagName==='LABEL') lbl.style.display='';
}
function srvMostraInstalar(){
  var p=el('entSrvPainel'); if(!p) return;
  srvEscondeLogin();
  var min=window.__srvPolitica||12;
  p.innerHTML=
    '<div style="margin-top:6px;padding:10px 12px;background:#fff7e0;border-left:3px solid #8C4D00;font-size:12.5px;color:#5b4300">'+
      '<strong>Primeira configuração do servidor.</strong> Cria o gestor inicial. O token de instalação está no <code>config.php</code> (peça à TI). '+
      'Depois de entrar, use a aba Sincronização da Área Admin para migrar os dados e os usuários do painel antigo.</div>'+
    '<label for="instToken">Token de instalação</label><input type="password" id="instToken" autocomplete="off">'+
    '<label for="instLogin">Usuário do gestor</label><input type="text" id="instLogin" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="ex.: e-mail institucional">'+
    '<label for="instPosto">Posto/Grad e nome</label><div style="display:flex;gap:6px"><input type="text" id="instPosto" style="flex:0 0 90px" placeholder="Posto"><input type="text" id="instNome" placeholder="Nome completo"></div>'+
    '<label for="instSenha">Senha (mínimo '+min+' caracteres)</label><input type="password" id="instSenha" autocomplete="new-password">'+
    '<label for="instSenha2">Repita a senha</label><input type="password" id="instSenha2" autocomplete="new-password">'+
    '<button type="button" id="instBtn" class="uent-go">Criar gestor e entrar</button>'+
    '<button type="button" id="instCancel" style="margin-top:8px;width:100%;background:none;border:1px solid #c8cdd4;padding:8px;cursor:pointer;font:12px Roboto,sans-serif;color:#5b6572">Voltar</button>';
  srvMsg('');
  el('instCancel').addEventListener('click', function(){ p.innerHTML=''; srvMostraLogin(); var bt=el('entBootstrap'); if(bt) bt.style.display=''; });
  el('instBtn').addEventListener('click', async function(){
    var d={ token:el('instToken').value.trim(), login:el('instLogin').value.trim().toLowerCase(), posto:el('instPosto').value.trim(),
            nome:el('instNome').value.trim(), senha:el('instSenha').value };
    if(!d.token||!d.login||!d.nome||!d.senha){ srvMsg('Preencha todos os campos.'); return; }
    if(d.senha!==el('instSenha2').value){ srvMsg('As senhas não são iguais.'); return; }
    srvMsg('Criando…', true);
    try{ var j=await SRV.instalar(d); p.innerHTML=''; srvMostraLoginCampos(); srvMsg(''); await srvAbreSessao(j.usuario); }
    catch(e){ srvMsg(e.message); }
  });
}
window.__srvSessaoExpirada=function(){
  if(!window.SESS || window.__srvExpirando) return;
  window.__srvExpirando=true;
  try{ window.__logoutUnificado(); }catch(e){}
  srvMostraLogin();
  srvMsg('Sua sessão terminou (limite de '+'8 h ou saída em outra aba). Entre novamente — o que não foi enviado continua guardado neste computador.');
  setTimeout(function(){ window.__srvExpirando=false; }, 1500);
};
/* ---------- usuários (Área Admin do gestor) ---------- */
function srvUsuariosAplicar(j){
  if(!j || !Array.isArray(j.usuarios)) return;
  window.__srvUsers=j.usuarios; window.__srvUsersVersao=j.versao||'';
  try{ renderCredList(); }catch(e){}
}
window.__srvUsuariosAplicar=srvUsuariosAplicar;
async function srvCarregaUsuarios(){
  try{ srvUsuariosAplicar(await SRV.usuarios()); }
  catch(e){ credNote('Não foi possível carregar os usuários do servidor: '+e.message); }
}
async function srvSyncUsuarios(store){
  var lista=(store&&store.credenciais||[]).map(function(c){
    var o={ id:c.id||0, login:c.login||'', nome:c.nome||c.rotulo||'', posto:c.posto||'', omUser:c.omUser||'', tel:c.tel||'',
            email:c.email||'', perfil:c.perfil, escopo:c.escopo||'' };
    if(c.hash && c.salt){ o.salt=c.salt; o.hash=c.hash; }      // senha nova/redefinida (provisória)
    return o;
  });
  try{
    var j=await SRV.usuariosPost({ acao:'sincronizar', versao:window.__srvUsersVersao||'', credenciais:lista });
    srvUsuariosAplicar(j);
  }catch(e){
    alert('O servidor recusou a alteração de usuários: '+e.message);
    credNote('Alteração NÃO gravada: '+e.message);
    srvCarregaUsuarios();
  }
}
function srvCredInfo(c){
  if(!(window.SRV && SRV.ativo()) || !c) return '';
  var x=function(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]; }); };
  var bits=['usuário: <code>'+x(c.login||'(definido ao salvar)')+'</code>'];
  if(c.trocaPendente) bits.push('senha provisória · troca no 1º acesso');
  bits.push(c.ultimoLogin? 'último acesso '+x(String(c.ultimoLogin).slice(0,16)) : 'nunca entrou');
  return '<br><small style="color:#5b6572">'+bits.join(' · ')+'</small>';
}

/* ---------- Primeira configuração (só quando não há credencial alguma) ---------- */""", 'bloco: funções do servidor')

# =====================================================================
# versão
# =====================================================================
patch(VERSAO_ORIG, VERSAO_NOVA, 'carimbo de versão')

# =====================================================================
# saída + rol inicial no schema.sql
# =====================================================================
io.open(DST, 'w', encoding='utf-8').write(s)
print(f'\n{n} patches aplicados -> {os.path.relpath(DST)} ({len(s.encode("utf-8"))//1024} KB)')

m = re.search(r'const ROSTER_EMBED = (\{.*?\});\n', s)
if not m:
    sys.exit('[ERRO] ROSTER_EMBED não encontrado no painel.')
rol = json.loads(m.group(1))
def sq(v):
    return 'NULL' if v is None else "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"
linhas = []
for om in sorted(rol):
    r = rol[om]
    extra = {k: v for k, v in r.items() if k not in ('nome', 'ods', 'guarnicao')}
    linhas.append('  (%s, %s, %s, %s, %s)' % (sq(om), sq(r.get('nome')), sq(r.get('ods')), sq(r.get('guarnicao')),
                                            sq(json.dumps(extra, ensure_ascii=False)) if extra else 'NULL'))
bloco = ('-- [ROL-INICIAL]\n-- %d OMs do ROSTER_EMBED da u2.30. INSERT IGNORE: não sobrescreve o que o gestor já editou.\n'
         'INSERT IGNORE INTO `roster` (`om`, `nome`, `ods`, `guarnicao`, `extra`) VALUES\n%s;\n-- [/ROL-INICIAL]') % (len(linhas), ',\n'.join(linhas))
sql = io.open(SCHEMA, encoding='utf-8').read()
sql2, k = re.subn(r'-- \[ROL-INICIAL\].*?-- \[/ROL-INICIAL\]', lambda _m: bloco, sql, flags=re.S)
if k != 1:
    sys.exit('[ERRO] marcadores [ROL-INICIAL] não encontrados no schema.sql')
io.open(SCHEMA, 'w', encoding='utf-8').write(sql2)
print(f'rol inicial: {len(linhas)} OMs gravadas em {os.path.relpath(SCHEMA)}')

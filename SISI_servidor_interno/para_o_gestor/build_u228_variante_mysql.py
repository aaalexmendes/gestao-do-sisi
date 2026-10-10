# -*- coding: utf-8 -*-
"""build_u228.py — Painel Unificado SISI u2.28 (sobre o u2.27)

Backend interno (MySQL/PHP) no lugar de jsonbin.io + Google Apps Script:
 • Novo cartão "Servidor interno (MySQL/PHP)" na Área Admin: URL da api.php + token.
   Ao salvar, as três camadas de sincronização passam a apontar para ele:
     - estado do gestor (ex-JSONBin)  → api.php?rota=estado
     - pacotes das OMs (ex-Apps Script) → api.php (POST) / ?rota=pacotes / ciencia(s)
     - API ?om= / ?all= / ?roster=      → api.php
 • O JSONBin e o Apps Script continuam funcionando se o servidor interno não estiver
   configurado (compatibilidade com a implantação atual).
"""
import io

SRC='b27.html'; DST='out28.html'
s=io.open(SRC,encoding='utf-8').read(); n=0
def patch(old,new,tag):
    global s,n
    c=s.count(old); assert c==1, f'[{tag}] âncora {c}x'
    s=s.replace(old,new,1); n+=1; print('  ok ',tag)

# ================================================================= 1. helper global
patch("""const DATA = {""",
"""// ==================== u2.28 · SERVIDOR INTERNO (MySQL/PHP) ====================
/* Configuração única do backend interno. Quando ativo, substitui o jsonbin.io e o
   Google Apps Script sem mudar os formatos: a api.php implementa os mesmos contratos. */
window.SRV = (function(){
  const K='sisi_srv_v1';
  function get(){ try{ const o=JSON.parse(localStorage.getItem(K)||'null'); if(o && o.url) return o; }catch(e){} return null; }
  function set(url, token){ const u=String(url||'').trim().replace(/\\/+$/,''); if(!u){ localStorage.removeItem(K); return null; }
    const o={url:u, token:String(token||'').trim(), salvoEm:new Date().toISOString()}; localStorage.setItem(K, JSON.stringify(o)); return o; }
  function ativo(){ return !!get(); }
  function url(qs){ const o=get(); if(!o) return ''; return o.url+(qs? ((o.url.indexOf('?')>=0?'&':'?')+qs) : ''); }
  function headers(extra){ const o=get()||{}; return Object.assign({'X-Token':o.token||''}, extra||{}); }
  async function ping(){ const r=await fetch(url('ping=1'),{cache:'no-store'}); const j=await r.json().catch(()=>null); if(!r.ok||!j||j.ok!==true) throw new Error((j&&j.erro)||('HTTP '+r.status)); return j; }
  return { get, set, ativo, url, headers, ping };
})();
const DATA = {""",
'SRV: helper do servidor interno (antes de qualquer uso)')

# ================================================================= 2. JSONBin → estado
patch("""  function getCfg(){""",
"""  // u2.28 — com servidor interno ativo, o "bin" é a rota=estado da api.php
  function binGetUrl(cfg){ return SRV.ativo()? SRV.url('rota=estado') : `https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}/latest`; }
  function binPutUrl(cfg){ return SRV.ativo()? SRV.url('rota=estado') : `https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}`; }
  function binPutMethod(){ return SRV.ativo()? 'POST' : 'PUT'; }
  function binHeaders(cfg){ return SRV.ativo()? SRV.headers() : keyHeader(cfg); }
  function binPronto(cfg){ return SRV.ativo() || !!cfg.binId; }
  function getCfg(){""",
'JSONBin: roteamento condicional')

patch("""    if(!cfg.binId){ if(!silent){ setBinStatus('warn','Informe o Bin ID para carregar.'); } return null; }
    try{
      const resp=await fetch(`https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}/latest`,
        { method:'GET', cache:'no-store', headers: Object.assign({}, keyHeader(cfg)) });""",
"""    if(!binPronto(cfg)){ if(!silent){ setBinStatus('warn','Informe o Bin ID (ou configure o servidor interno) para carregar.'); } return null; }
    try{
      const resp=await fetch(binGetUrl(cfg),
        { method:'GET', cache:'no-store', headers: Object.assign({}, binHeaders(cfg)) });""",
'loadFromBin')

patch("""    if(!cfg.binId){ setBinStatus('warn','Informe o Bin ID antes de testar.'); return; }
    setBinStatus('','Testando conexão…');
    try{
      const resp=await fetch(`https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}/latest`,
        { method:'GET', cache:'no-store', headers: Object.assign({}, keyHeader(cfg)) });""",
"""    if(!binPronto(cfg)){ setBinStatus('warn','Informe o Bin ID (ou configure o servidor interno) antes de testar.'); return; }
    setBinStatus('','Testando conexão…');
    try{
      const resp=await fetch(binGetUrl(cfg),
        { method:'GET', cache:'no-store', headers: Object.assign({}, binHeaders(cfg)) });""",
'testConnection')

patch("""    if(!cfg.binId || !cfg.key){ setBinStatus('warn','Para enviar é preciso Bin ID e uma chave com permissão de escrita.'); return; }""",
"""    if(!SRV.ativo() && (!cfg.binId || !cfg.key)){ setBinStatus('warn','Para enviar é preciso Bin ID e uma chave com permissão de escrita (ou configure o servidor interno).'); return; }""",
'publish: pré-condição')

patch("""      const resp=await fetch(`https://api.jsonbin.io/v3/b/${encodeURIComponent(cfg.binId)}`,
        { method:'PUT', headers: Object.assign({'Content-Type':'application/json'}, keyHeader(cfg)), body: JSON.stringify(payload) });""",
"""      const resp=await fetch(binPutUrl(cfg),
        { method:binPutMethod(), headers: Object.assign({'Content-Type':'application/json'}, binHeaders(cfg)), body: JSON.stringify(payload) });""",
'publish: envio')

# sem o limite de 100 KB do JSONBin, não comprime quando o servidor é interno
patch("""    let planGz=null;
    if(plan){""",
"""    let planGz=null;
    const comprimir = !SRV.ativo();   // o MySQL não tem o limite de 100 KB do jsonbin
    if(plan && comprimir){""",
'publish: compressão só no jsonbin')
patch("""    const execGz = exec? await gzipJson(exec) : null;
    const prevGz = execPrevRaw? await gzipJson(execPrevRaw) : null;
    const przGz = prazos? await gzipJson(prazos) : null;""",
"""    const execGz = (exec && comprimir)? await gzipJson(exec) : null;
    const prevGz = (execPrevRaw && comprimir)? await gzipJson(execPrevRaw) : null;
    const przGz = (prazos && comprimir)? await gzipJson(prazos) : null;""",
'publish: compressão condicional')

# ================================================================= 3. Apps Script → api.php
patch("""              if(url && !/^https:\\/\\/script\\.google(usercontent)?\\.com\\//.test(url)){
                el('gasStatus').textContent='URL inválida — deve começar com https://script.google.com/'; return;
              }""",
"""              if(url && !/^https:\\/\\/script\\.google(usercontent)?\\.com\\//.test(url) && !/^https?:\\/\\/.+\\/api\\.php(\\?.*)?$/i.test(url)){
                el('gasStatus').textContent='URL inválida — deve ser o web app do Apps Script (https://script.google.com/…) ou a api.php do servidor interno'; return;
              }""",
'GAS: aceita a api.php')

# ================================================================= 4. cartão na Área Admin
patch("""        <div class="admin-section" data-adm="sync">
          <h3>Compartilhamento · JSONBin</h3>""",
"""        <div class="admin-section" data-adm="sync">
          <h3>Servidor interno &#183; MySQL / PHP</h3>
          <p>Aponta o painel para a <strong>api.php</strong> instalada no servidor da OM (banco MySQL via phpMyAdmin). Quando configurado,
            substitui o JSONBin <em>e</em> o Google Apps Script: estado do gestor, pacotes das OMs, fotos, auditoria e ci&#234;ncias passam a ficar na rede interna.</p>
          <div class="admin-config-row">
            <label><span>URL da api.php</span><input type="url" id="srvUrl" placeholder="http://servidor-da-om/sisi/api.php" autocomplete="off"></label>
            <label><span>Token (config.php)</span><input type="password" id="srvToken" placeholder="o mesmo 'token' do config.php" autocomplete="off"></label>
          </div>
          <div class="admin-preview-actions" style="border-top:none;padding-top:8px;">
            <button class="admin-btn admin-btn-success" id="srvSalvarBtn" type="button">Salvar e testar</button>
            <button class="admin-btn admin-btn-secondary" id="srvLimparBtn" type="button">Remover (voltar ao JSONBin/Apps Script)</button>
          </div>
          <div class="admin-config-status" id="srvStatus">&#8212;</div>
        </div>
        <div class="admin-section" data-adm="sync">
          <h3>Compartilhamento · JSONBin</h3>""",
'HTML: cartão do servidor interno')

patch("""  $('adminClear2026Btn').addEventListener('click',clear2026);""",
"""  $('adminClear2026Btn').addEventListener('click',clear2026);
  /* u2.28 — servidor interno */
  function srvRender(){ const o=SRV.get(); const st=$('srvStatus'); if(!st) return;
    if($('srvUrl')) $('srvUrl').value=o?o.url:''; if($('srvToken')) $('srvToken').value=o?o.token:'';
    st.className='admin-config-status'+(o?' ok':''); st.textContent=o? ('Ativo: '+o.url+' · salvo em '+new Date(o.salvoEm).toLocaleString('pt-BR')) : 'Não configurado — o painel usa JSONBin e Apps Script.'; }
  $('srvSalvarBtn').addEventListener('click',async()=>{
    const url=$('srvUrl').value.trim(), tok=$('srvToken').value.trim(); const st=$('srvStatus');
    if(!url){ st.className='admin-config-status warn'; st.textContent='Informe a URL da api.php.'; return; }
    if(!/^https?:\\/\\/.+\\/api\\.php(\\?.*)?$/i.test(url)){ st.className='admin-config-status warn'; st.textContent='A URL deve terminar em /api.php (ex.: http://192.168.0.10/sisi/api.php).'; return; }
    SRV.set(url,tok);
    // as três camadas passam a apontar para o servidor interno
    try{ setGas({url:url, token:tok, liberado:true}); }catch(e){}
    try{ if(window.PM&&PM._setApiUrl) PM._setApiUrl(url); }catch(e){}
    st.className='admin-config-status'; st.textContent='Testando…';
    try{ const j=await SRV.ping(); st.className='admin-config-status ok'; st.textContent='Conexão OK · '+(j.versao||'')+' · PHP '+(j.php||'?')+' · '+url; alog('Servidor interno configurado: '+url,'ok'); srvRender(); }
    catch(e){ st.className='admin-config-status err'; st.textContent='Salvo, mas o teste falhou: '+e.message+' — confira a URL, o config.php e se o servidor está na mesma rede.'; }
  });
  $('srvLimparBtn').addEventListener('click',()=>{ if(!confirm('Remover o servidor interno e voltar ao JSONBin/Apps Script?')) return; SRV.set('',''); try{ setGas({url:'',token:'',liberado:false}); }catch(e){} srvRender(); alog('Servidor interno removido.','info'); });
  srvRender();""",
'admin: ações do cartão')

# PM: expõe setter da apiUrl (contrato ?om= / ?all= / ?roster=)
patch("""  _audit:auditPush, _mergeAudit:mergeAuditoria,""",
"""  _audit:auditPush, _mergeAudit:mergeAuditoria, _setApiUrl:(u)=>{ cfg.apiUrl=u||''; lsSet(LS.cfg,cfg); try{ renderCtx(); }catch(e){} },""",
'PM._setApiUrl')

# ================================================================= 5. versão
patch('u2.27 &#183; 21/09/2026', 'u2.28 &#183; 01/10/2026', 'carimbo de versão')

io.open(DST,'w',encoding='utf-8').write(s)
print(f'\n{n} patches -> {DST}')

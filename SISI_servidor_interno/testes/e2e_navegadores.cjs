/* =====================================================================
   Painel Unificado SISI · testes/e2e_navegadores.cjs  (pedido 6 da TI)
   Fluxo completo com o GESTOR e DUAS OMs em navegadores separados
   (contextos isolados do Chromium: cookies e localStorage independentes),
   com a internet BLOQUEADA (só o host do servidor é permitido).

   Requer um servidor de HOMOLOGAÇÃO recém-instalado (banco vazio):
     BASE=http://127.0.0.1:8080/sisi  TOKEN=<token do config.php>  \
     MYSQL="mariadb sisi"  node testes/e2e_navegadores.cjs
   (MYSQL é opcional: usado só para simular a expiração da sessão de 8 h.)
   Playwright: npm i playwright  (ou NODE_PATH apontando para a instalação global)
   ===================================================================== */
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const crypto = require('crypto');

const BASE = (process.env.BASE || 'http://127.0.0.1:8080/sisi').replace(/\/$/, '');
const API = BASE + '/api.php';
const TOKEN = process.env.TOKEN || '';
const MYSQL = process.env.MYSQL || '';
const HOST = new URL(BASE).host;
const PNG16 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAGUlEQVR4nGOUsznBQApgIkn1qIZRDUNKAwCpbwFC9fgXLgAAAABJRU5ErkJggg==';

let ok = 0, falhas = 0; const externos = [], erros = [];
function t(nome, cond, det) { if (cond) { ok++; console.log('  ✓ ' + nome); } else { falhas++; console.log('  ✗ ' + nome + (det !== undefined ? '  → ' + String(typeof det === 'string' ? det : JSON.stringify(det)).slice(0, 300) : '')); } }
const sha = (s) => crypto.createHash('sha256').update(s, 'utf8').digest('hex');

async function novoNavegador(browser, nome) {
  const ctx = await browser.newContext({ locale: 'pt-BR', acceptDownloads: true });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: new URL(BASE).origin });   // o painel copia senhas geradas
  // internet bloqueada: qualquer host que não seja o servidor interno é abortado e anotado
  await ctx.route('**/*', (route) => {
    const u = new URL(route.request().url());
    if (u.host === HOST || u.protocol === 'data:' || u.protocol === 'blob:') return route.continue();
    externos.push(nome + ': ' + u.href); return route.abort();
  });
  const page = await ctx.newPage();
  page.on('dialog', (d) => { page.__ultimoDialogo = d.message(); d.accept().catch(() => {}); });
  page.on('pageerror', (e) => erros.push(nome + ' (erro JS): ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 40[13]|Failed to load resource/.test(m.text())) erros.push(nome + ' (console): ' + m.text()); });
  await page.addInitScript(() => { document.addEventListener('securitypolicyviolation', (e) => { (window.__csp = window.__csp || []).push(e.violatedDirective + ' ' + e.blockedURI); }); });
  return { ctx, page, nome };
}
async function abre(n) { await n.page.goto(BASE + '/index.html', { waitUntil: 'load' }); await n.page.waitForFunction(() => window.SRV && document.getElementById('entMsg') && !/Conectando/.test(document.getElementById('entMsg').textContent), null, { timeout: 20000 }); }
async function entra(n, login, senha) {
  await n.page.fill('#entLoginU', login); await n.page.fill('#entSenhaU', senha); await n.page.click('#entBtnEntrar');
  await n.page.waitForFunction(() => (window.SESS && document.getElementById('entryOverlay').style.display === 'none') || document.getElementById('entSenhaNova') || /inválid|bloque|aguarde/i.test(document.getElementById('entMsg').textContent), null, { timeout: 20000 });
}
async function trocaSenha(n, nova) {
  await n.page.fill('#entSenhaNova', nova); await n.page.fill('#entSenhaNova2', nova); await n.page.click('#entTrocarBtn');
  await n.page.waitForFunction(() => window.SESS && document.getElementById('entryOverlay').style.display === 'none', null, { timeout: 20000 });
  await fechaPopups(n);
}
async function fechaPopups(n) {   // boas-vindas / avisos exibidos após o login
  for (let i = 0; i < 10; i++) {
    await n.page.waitForTimeout(300);
    if (!(await n.page.isVisible('#msgOverlay'))) return;
    await n.page.click('#msgPopBtn');
  }
}
const api = (n, qs, opt) => n.page.evaluate(async ([u, o]) => { const r = await fetch(u, o || {}); let b = ''; try { b = await r.text(); } catch (e) {} return { s: r.status, b }; }, [API + (qs || ''), opt]);
const status = async (n, url) => n.page.evaluate(async (u) => (await fetch(u)).status, url);
const fotoCarrega = (n, url) => n.page.evaluate((u) => new Promise((res) => { const i = new Image(); i.onload = () => res(i.naturalWidth); i.onerror = () => res(-1); i.src = u; }), url);
async function enviaPacoteComFoto(n, titulo) {
  return n.page.evaluate(async ([tit, png]) => {
    const d = PM.getOmData();
    d.registros = d.registros || [];
    d.registros.push({ id: 'r' + Date.now(), data: '2026-10-10', tipo: 'normal', titulo: tit, comentario: 'teste ponta a ponta', fotos: [{ name: 'foto.png', dataUrl: png }] });
    await PM.enviarServidor();
    return { msg: '', regs: d.registros.length };
  }, [titulo, PNG16]);
}

(async () => {
  const browser = await chromium.launch();
  const G = await novoNavegador(browser, 'gestor'), A = await novoNavegador(browser, 'OM A'), Bn = await novoNavegador(browser, 'OM B');

  console.log('[1] carga inicial sem nada no navegador → detecta a API sozinho');
  await abre(G);
  t('painel detecta ./api.php sem configuração', await G.page.evaluate(() => SRV.ativo() && /\/sisi\/api\.php$/.test(SRV.base())), await G.page.evaluate(() => SRV.base()));
  t('tela de entrada pede USUÁRIO e senha', await G.page.isVisible('#entLoginU') && await G.page.isVisible('#entSenhaU'));
  t('servidor novo oferece "Primeira configuração"', await G.page.isVisible('#entBootstrap'));
  t('nenhum token guardado no navegador', await G.page.evaluate(() => !JSON.stringify(localStorage).match(/token/i)));

  console.log('[2] gestor: instalação, migração do painel antigo e cadastro de OM');
  await G.page.click('#entBootstrap');
  await G.page.fill('#instToken', TOKEN); await G.page.fill('#instLogin', 'gestor.sisi'); await G.page.fill('#instPosto', 'Cel');
  await G.page.fill('#instNome', 'Gestor do Projeto'); await G.page.fill('#instSenha', 'farol ancora bussola mares'); await G.page.fill('#instSenha2', 'farol ancora bussola mares');
  await G.page.click('#instBtn');
  await G.page.waitForFunction(() => window.SESS && window.SESS.tipo === 'gestor', null, { timeout: 20000 });
  t('gestor inicial criado e logado', await G.page.evaluate(() => SESS.tipo === 'gestor' && /GESTOR/.test(document.getElementById('sessBarTxt').textContent)));
  t('cookie de sessão é HttpOnly (JS não lê)', await G.page.evaluate(() => !/SISI_SESS/.test(document.cookie)));
  // arquivo .json no formato do JSONBin (estado + credenciais antigas sha256(salt+senha))
  const salt = 'a1b2c3d4e5f60718';
  const registro = { record: { meta: { schema: 4 }, msgs: { avisos: [{ id: 'av1', texto: 'Aviso migrado do JSONBin', criadoEm: '2026-10-09T10:00:00Z', escopo: 'todos' }], diretas: [], updatedAt: '2026-10-09T10:00:00Z' },
    ciclo: { ano: 2027, aberto: false, alteradoEm: '2026-10-01T10:00:00Z' },
    auth: { updatedAt: '2026-10-09T10:00:00Z', credenciais: [ { nome: 'Ana Silva', posto: 'Cap', email: '', perfil: 'om', escopo: 'BAPV', salt, hash: sha(salt + 'Senha-Antiga-OM-A'), rotulo: 'Cap Ana Silva' },
                                                                { nome: 'Carla Lima', posto: 'Maj', perfil: 'ods', escopo: 'COMPREP', salt, hash: sha(salt + 'Senha-Antiga-ODS'), rotulo: 'Maj Carla Lima' } ] } } };
  await fechaPopups(G);
  await G.page.click('#adminFooterBtn'); await G.page.click('#admTabs .adm-tab[data-g="sync"]');
  t('cartão do servidor mostra o modo automático', /Ativo \(automático\)/.test(await G.page.textContent('#srvStatus')), await G.page.textContent('#srvStatus'));
  await G.page.setInputFiles('#srvMigArquivo', { name: 'jsonbin.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(registro)) });
  await G.page.waitForFunction(() => /concluída|Falha/.test(document.getElementById('srvMigStatus').textContent), null, { timeout: 30000 });
  const mig = await G.page.textContent('#srvMigStatus');
  t('migração do arquivo .json concluída (estado + 2 usuários)', /concluída/.test(mig) && /2 usuário/.test(mig), mig);
  const loginA = await G.page.evaluate(() => (window.__srvUsers.find(u => u.escopo === 'BAPV') || {}).login);
  t('login gerado para a OM A migrada', loginA === 'om.bapv.silva', loginA);
  // cadastro da OM B pela Área Admin (senha provisória)
  await G.page.click('#admTabs .adm-tab[data-g="acessos"]');
  await G.page.fill('#credNome', 'Bruno Souza'); await G.page.fill('#credPosto', 'Ten');
  await G.page.selectOption('#credPerfil', 'om'); await G.page.selectOption('#credEscopo', 'BABE');
  await G.page.fill('#credLogin', 'om.babe.souza'); await G.page.fill('#credSenha', 'Provisoria-OM-B-2026');
  await G.page.click('#credAddBtn');
  await G.page.waitForFunction(() => (window.__srvUsers || []).some(u => u.login === 'om.babe.souza' && u.id), null, { timeout: 15000 });
  t('gestor cadastra a OM B pela Área Admin (grava direto no servidor)', true);
  t('lista mostra login e "troca no 1º acesso"', /om\.babe\.souza/.test(await G.page.textContent('#credList')) && /troca no 1º acesso/.test(await G.page.textContent('#credList')));
  await G.page.click('#adminModalCloseBtn');

  console.log('[3] OM A e OM B: primeiro acesso com troca obrigatória, envio de pacote com foto');
  await abre(A); await entra(A, 'om.bapv.silva', 'Senha-Antiga-OM-A');
  t('OM A entra com a senha do painel antigo e cai na troca obrigatória', await A.page.isVisible('#entSenhaNova'));
  await trocaSenha(A, 'girassol azul na varanda');
  t('OM A no painel, restrita à própria OM', await A.page.evaluate(() => SESS.tipo === 'om' && SESS.om === 'BAPV'));
  t('OM A recebeu o aviso migrado (estado lido após o login)', await A.page.evaluate(() => JSON.stringify(localStorage.getItem('sisi_msgs_v1') || '').includes('Aviso migrado')));
  await A.page.waitForFunction(() => window.PM && PM.getOmData && PM.getOmData(), null, { timeout: 15000 });
  await enviaPacoteComFoto(A, 'Muro da OM A');
  t('OM A enviou o pacote com foto', /Enviado ao servidor/.test(A.page.__ultimoDialogo || ''), A.page.__ultimoDialogo);
  await abre(Bn); await entra(Bn, 'om.babe.souza', 'Provisoria-OM-B-2026');
  t('OM B entra com a senha provisória e cai na troca obrigatória', await Bn.page.isVisible('#entSenhaNova'));
  await trocaSenha(Bn, 'pinheiro verde da serra');
  await Bn.page.waitForFunction(() => window.PM && PM.getOmData && PM.getOmData(), null, { timeout: 15000 });
  await enviaPacoteComFoto(Bn, 'Portão da OM B');
  t('OM B enviou o pacote com foto', /Enviado ao servidor/.test(Bn.page.__ultimoDialogo || ''), Bn.page.__ultimoDialogo);

  console.log('[4] recuperação: OM A em outro navegador limpo');
  const A2 = await novoNavegador(browser, 'OM A (outro navegador)');
  await abre(A2); await entra(A2, 'om.bapv.silva', 'girassol azul na varanda');
  await A2.page.waitForFunction(() => window.PM && PM.getOmData && (PM.getOmData().registros || []).length > 0, null, { timeout: 20000 }).catch(() => {});
  const rec = await A2.page.evaluate(() => { const d = PM.getOmData() || {}; const r = (d.registros || [])[0] || {}; return { n: (d.registros || []).length, tit: r.titulo, ref: ((r.fotos || [])[0] || {}).ref }; });
  t('OM A recupera o próprio registro do servidor', rec.n >= 1 && rec.tit === 'Muro da OM A', rec);
  const urlFotoA = API + '?rota=foto&om=BAPV&nome=' + encodeURIComponent(rec.ref || '');
  t('foto recuperada exibida pela api.php (rota=foto)', (await fotoCarrega(A2, urlFotoA)) === 16, urlFotoA);
  await fechaPopups(A2);
  await A2.page.evaluate(() => document.querySelector('#mainTabs .view-tab[data-view="projeto"]').click());
  await A2.page.evaluate(() => PM.showView('om-registros'));
  await A2.page.evaluate(() => { const i = document.querySelector('#regList img'); if (i) i.scrollIntoView(); });
  await A2.page.waitForFunction(() => { const i = document.querySelector('#regList img[src*="rota=foto"]'); return i && i.complete && i.naturalWidth > 0; }, null, { timeout: 10000 }).catch(() => {});
  const img = await A2.page.evaluate(() => { const i = document.querySelector('#regList img[src*="rota=foto"]'); return i ? { src: i.getAttribute('src'), w: i.naturalWidth, ok: i.complete } : null; });
  t('aba Registros exibe a foto vinda do servidor', !!img && img.w === 16, img);

  console.log('[5] gestor vê os dois pacotes e as duas fotos');
  await G.page.reload(); await G.page.waitForFunction(() => window.SESS && SESS.tipo === 'gestor', null, { timeout: 20000 });
  t('sessão do gestor restaurada pelo cookie após recarregar', true);
  const pac = await api(G, '?rota=pacotes&lista=1');
  t('gestor lista BAPV e BABE', /"BAPV"/.test(pac.b) && /"BABE"/.test(pac.b), pac.b);
  const tudo = JSON.parse((await api(G, '?rota=pacotes')).b);
  const fotoDe = (om) => { const p = tudo.pacotes.find(x => x.om === om) || {}; const r = (p.registros || [])[0] || {}; return ((r.fotos || [])[0] || {}).ref; };
  const fB = fotoDe('BABE');
  t('gestor carrega a foto da OM A', (await fotoCarrega(G, urlFotoA)) === 16);
  t('gestor carrega a foto da OM B', (await fotoCarrega(G, API + '?rota=foto&om=BABE&nome=' + encodeURIComponent(fB || ''))) === 16, fB);
  await G.page.waitForFunction(() => window.PM && PM.getDB && Object.keys(PM.getDB()).length >= 2, null, { timeout: 20000 }).catch(() => {});
  t('consolidado do gestor (PM) com as duas OMs', await G.page.evaluate(() => !!(PM.getDB().BAPV && PM.getDB().BABE)), await G.page.evaluate(() => Object.keys(PM.getDB())));

  console.log('[6] tentativas de acesso entre OMs (todas devem falhar)');
  const pkgB = JSON.stringify({ v: 3, om: 'BABE', dados: { om: 'BABE', savedAt: '2026-10-10T13:00:00Z', registros: [] }, fotos: [] });
  t('OM A lê dados da OM B → 403', (await api(A, '?om=BABE')).s === 403);
  t('OM A lê pacote da OM B → 403', (await api(A, '?rota=pacotes&om=BABE')).s === 403);
  t('OM A baixa foto da OM B → 403', (await api(A, '?rota=foto&om=BABE&nome=' + encodeURIComponent(fB || 'x.png'))).s === 403);
  t('OM A exibe foto da OM B em <img> → não carrega', (await fotoCarrega(A, API + '?rota=foto&om=BABE&nome=' + encodeURIComponent(fB || ''))) === -1);
  t('OM A envia pacote como OM B → 403', (await api(A, '', { method: 'POST', body: pkgB })).s === 403);
  t('OM A grava o estado → 403', (await api(A, '?rota=estado', { method: 'POST', body: '{"meta":{}}' })).s === 403);
  t('OM A lista usuários → 403', (await api(A, '?rota=usuarios')).s === 403);
  t('acesso direto a uploads/BABE/ → 403', (await status(A, BASE + '/uploads/BABE/' + encodeURIComponent(fB || 'x.png'))) === 403);
  t('acesso direto ao config.php → 403', (await status(A, BASE + '/config.php')) === 403);
  t('nome de foto com ../ → 400', (await api(A, '?rota=foto&om=BAPV&nome=..%2F..%2Fconfig.php')).s === 400);
  const est = await api(A, '?rota=estado');
  t('estado lido pela OM não tem credenciais/hashes', est.s === 200 && !/"auth"|"hash"|"salt"/.test(est.b), est.b.slice(0, 200));
  const ods = await novoNavegador(browser, 'ODS');
  await abre(ods); await entra(ods, 'ods.comprep.lima', 'Senha-Antiga-ODS'); await trocaSenha(ods, 'caneca de barro amarela');
  t('ODS lê as duas OMs do COMPREP', /BAPV/.test((await api(ods, '?rota=pacotes&lista=1')).b) && /BABE/.test((await api(ods, '?rota=pacotes&lista=1')).b));
  t('ODS vê a foto da OM B', (await fotoCarrega(ods, API + '?rota=foto&om=BABE&nome=' + encodeURIComponent(fB || ''))) === 16);
  t('ODS tenta gravar pacote de OM → 403', (await api(ods, '', { method: 'POST', body: pkgB })).s === 403);
  t('ODS não vê o botão Admin', !(await ods.page.isVisible('#adminFooterBtn')));

  console.log('[7] sessão expirada e saída');
  if (MYSQL) {
    execSync(`${MYSQL} -e "UPDATE sessoes s JOIN usuarios u ON u.id=s.usuario_id SET s.expira_em = NOW() - INTERVAL 1 MINUTE WHERE u.login='om.babe.souza'"`);
    await Bn.page.evaluate(() => fetch(SRV.url('rota=estado')));
    await Bn.page.waitForFunction(() => document.getElementById('entryOverlay').style.display !== 'none', null, { timeout: 10000 }).catch(() => {});
    t('sessão de 8 h vencida → painel volta à tela de entrada', await Bn.page.evaluate(() => document.getElementById('entryOverlay').style.display !== 'none' && /sessão terminou/.test(document.getElementById('entMsg').textContent)), await Bn.page.textContent('#entMsg'));
  } else console.log('  (pulado: defina MYSQL para simular a expiração)');
  await fechaPopups(A);
  await A.page.click('#sessBarSair');
  await A.page.waitForFunction(() => document.getElementById('entryOverlay').style.display !== 'none', null, { timeout: 10000 });
  await A.page.waitForTimeout(500);
  t('após "Sair", a sessão da OM A não vale mais → 401', (await api(A, '?om=BAPV')).s === 401);
  t('após "Sair", o consolidado não fica no navegador', await A.page.evaluate(() => !localStorage.getItem('sisiPM_all')));

  console.log('[8] rede e segurança da página');
  t('nenhuma requisição à internet (bibliotecas e fontes locais)', externos.length === 0, externos);
  const csp = (await Promise.all([G, A, Bn, A2, ods].map(n => n.page.evaluate(() => window.__csp || []).catch(() => [])))).flat();
  t('nenhuma violação da Content-Security-Policy', csp.length === 0, csp);
  t('bibliotecas carregadas (XLSX, Papa, Chart)', await G.page.evaluate(() => typeof XLSX !== 'undefined' && typeof Papa !== 'undefined' && typeof Chart !== 'undefined'));
  const pdf = await G.page.evaluate(async () => { try { await ensureHtml2Canvas(); await ensureJsPDF(); return typeof html2canvas === 'function' && !!(window.jspdf && window.jspdf.jsPDF); } catch (e) { return e.message; } });
  t('html2canvas e jsPDF carregam sob demanda (local)', pdf === true, pdf);
  t('fontes Roboto locais aplicadas', await G.page.evaluate(async () => { await document.fonts.ready; return document.fonts.check('16px Roboto') && [...document.fonts].some(f => f.family.replace(/"/g, '') === 'Roboto' && f.status === 'loaded'); }));
  t('sem erros de JavaScript nas páginas', erros.length === 0, erros);

  await browser.close();
  console.log(`\n${ok}/${ok + falhas} verificações aprovadas`);
  process.exit(falhas ? 1 : 0);
})().catch((e) => { console.error('ERRO:', e); process.exit(2); });

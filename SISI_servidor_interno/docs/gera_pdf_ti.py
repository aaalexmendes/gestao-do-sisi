# -*- coding: utf-8 -*-
"""gera_pdf_ti.py — gera Instrucoes_TI_Painel_SISI.pdf a partir dos guias em Markdown.

Parte 1 = LEIA-ME_TI.md (checklist) · Parte 2 = README_IMPLANTACAO.md (guia completo)
· Parte 3 = telas (docs/telas/*.png). Requer: reportlab e as fontes DejaVu
(Debian/Ubuntu: apt install fonts-dejavu-core). Uso: python3 docs/gera_pdf_ti.py
"""
import os, re, html
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer, Table, TableStyle,
                                Preformatted, PageBreak, Image, KeepTogether, ListFlowable, ListItem)

AQUI = os.path.dirname(os.path.abspath(__file__)); RAIZ = os.path.dirname(AQUI)
SAIDA = os.path.join(RAIZ, 'Instrucoes_TI_Painel_SISI.pdf')
FD = '/usr/share/fonts/truetype/dejavu/'
pdfmetrics.registerFont(TTFont('DV', FD + 'DejaVuSans.ttf'))
pdfmetrics.registerFont(TTFont('DVB', FD + 'DejaVuSans-Bold.ttf'))
pdfmetrics.registerFont(TTFont('DVM', FD + 'DejaVuSansMono.ttf'))
pdfmetrics.registerFontFamily('DV', normal='DV', bold='DVB', italic='DV', boldItalic='DVB')

AZUL = colors.HexColor('#00204a'); CINZA = colors.HexColor('#5b6572'); AMARELO = colors.HexColor('#FFCC00')
S = {
  'corpo': ParagraphStyle('corpo', fontName='DV', fontSize=9.2, leading=12.6, spaceAfter=4),
  'h1': ParagraphStyle('h1', fontName='DVB', fontSize=16, leading=20, textColor=AZUL, spaceBefore=4, spaceAfter=8),
  'h2': ParagraphStyle('h2', fontName='DVB', fontSize=12, leading=15, textColor=AZUL, spaceBefore=10, spaceAfter=5),
  'h3': ParagraphStyle('h3', fontName='DVB', fontSize=10, leading=13, textColor=AZUL, spaceBefore=7, spaceAfter=3),
  'cel': ParagraphStyle('cel', fontName='DV', fontSize=7.8, leading=10),
  'celh': ParagraphStyle('celh', fontName='DVB', fontSize=7.8, leading=10, textColor=colors.white),
  'code': ParagraphStyle('code', fontName='DVM', fontSize=7.2, leading=9.2, backColor=colors.HexColor('#f3f5f8'),
                         borderPadding=4, leftIndent=4, rightIndent=4, spaceBefore=3, spaceAfter=6),
  'capa1': ParagraphStyle('capa1', fontName='DVB', fontSize=24, leading=30, textColor=AZUL),
  'capa2': ParagraphStyle('capa2', fontName='DV', fontSize=13, leading=18, textColor=CINZA),
}

def inline(t):
    t = html.escape(t, quote=False)
    t = re.sub(r'`([^`]+)`', lambda m: '<font name="DVM" size="8">' + m.group(1) + '</font>', t)
    t = re.sub(r'\*\*([^*]+)\*\*', r'<b>\1</b>', t)
    t = re.sub(r'(?<![\w*])\*([^*\n]+)\*(?![\w*])', r'<i>\1</i>', t)
    return t

def md(texto, largura):
    """Markdown simples → flowables (títulos, parágrafos, listas, tabelas, código)."""
    out = []; linhas = texto.split('\n'); i = 0
    while i < len(linhas):
        l = linhas[i]
        if l.strip().startswith('```'):
            ind = len(l) - len(l.lstrip()); j = i + 1; bloco = []
            while j < len(linhas) and not linhas[j].strip().startswith('```'): bloco.append(linhas[j][ind:] if linhas[j][:ind].strip() == '' else linhas[j]); j += 1
            out.append(Preformatted('\n'.join(bloco), S['code'])); i = j + 1; continue
        if l.strip() in ('---', ''): i += 1; continue
        m = re.match(r'^(#{1,3}) (.*)', l)
        if m: out.append(Paragraph(inline(m.group(2)), S['h' + str(len(m.group(1)))])); i += 1; continue
        if l.startswith('|'):
            rows = []
            while i < len(linhas) and linhas[i].startswith('|'):
                cel = [c.strip() for c in linhas[i].strip().strip('|').split('|')]
                if not all(re.match(r'^:?-+:?$', c) for c in cel): rows.append(cel)
                i += 1
            n = max(len(r) for r in rows)
            data = [[Paragraph(inline(c), S['celh'] if k == 0 else S['cel']) for c in r + [''] * (n - len(r))] for k, r in enumerate(rows)]
            pesos = [max(len(r[c]) if c < len(r) else 0 for r in rows) for c in range(n)]
            pesos = [max(6, min(p, 70)) for p in pesos]; tot = sum(pesos)
            tb = Table(data, colWidths=[largura * p / tot for p in pesos], repeatRows=1)
            tb.setStyle(TableStyle([('BACKGROUND', (0, 0), (-1, 0), AZUL), ('VALIGN', (0, 0), (-1, -1), 'TOP'),
                                    ('GRID', (0, 0), (-1, -1), 0.4, colors.HexColor('#c8cdd4')),
                                    ('ROWBACKGROUNDS', (0, 1), (-1, -1), [colors.white, colors.HexColor('#f6f8fb')]),
                                    ('TOPPADDING', (0, 0), (-1, -1), 3), ('BOTTOMPADDING', (0, 0), (-1, -1), 3)]))
            out.append(tb); out.append(Spacer(1, 6)); continue
        if re.match(r'^\s*([-*]|\d+\.) ', l):
            itens = []
            while i < len(linhas) and (re.match(r'^\s*([-*]|\d+\.) ', linhas[i]) or (linhas[i].startswith('   ') and linhas[i].strip() and not linhas[i].strip().startswith('```'))):
                li = linhas[i]; nivel = (len(li) - len(li.lstrip())) // 3
                m2 = re.match(r'^\s*([-*]|\d+\.) (.*)', li)
                if m2: itens.append([nivel, m2.group(1), m2.group(2)])
                elif itens: itens[-1][2] += ' ' + li.strip()
                i += 1
            for nivel, marca, txt in itens:
                bullet = marca if marca[0].isdigit() else ('•' if nivel == 0 else '–')
                out.append(Paragraph(inline(txt), ParagraphStyle('li', parent=S['corpo'], leftIndent=12 + 12 * nivel,
                                     bulletIndent=2 + 12 * nivel, spaceAfter=2), bulletText=bullet))
            out.append(Spacer(1, 3)); continue
        par = [l.strip()]; i += 1
        while i < len(linhas) and linhas[i].strip() and not re.match(r'^(#|\||```|\s*([-*]|\d+\.) )', linhas[i]):
            par.append(linhas[i].strip()); i += 1
        out.append(Paragraph(inline(' '.join(par)), S['corpo']))
    return out

def rodape(c, doc):
    c.saveState(); c.setFillColor(AMARELO); c.rect(0, A4[1] - 6, A4[0], 6, stroke=0, fill=1)
    c.setFont('DV', 7.5); c.setFillColor(CINZA)
    c.drawString(18 * mm, 10 * mm, 'Painel Unificado SISI · Instalação no servidor interno (MySQL/PHP) · pacote u2.30-srv · API 2.0')
    c.drawRightString(A4[0] - 18 * mm, 10 * mm, 'página %d' % doc.page); c.restoreState()

def main():
    doc = BaseDocTemplate(SAIDA, pagesize=A4, leftMargin=18 * mm, rightMargin=18 * mm, topMargin=16 * mm, bottomMargin=18 * mm,
                          title='Painel Unificado SISI — Instruções para a TI (u2.30-srv)', author='Projeto SISI · COMPREP')
    fr = Frame(doc.leftMargin, doc.bottomMargin, doc.width, doc.height, id='f')
    doc.addPageTemplates([PageTemplate(id='p', frames=[fr], onPage=rodape)])
    W = doc.width; st = []
    st += [Spacer(1, 30 * mm), Paragraph('COMANDO DA AERONÁUTICA · PROJETO SISI', ParagraphStyle('pre', fontName='DVM', fontSize=9, textColor=CINZA)),
           Spacer(1, 6 * mm), Paragraph('Painel Unificado SISI', S['capa1']),
           Paragraph('Instalação no servidor interno — MySQL / PHP<br/>Instruções para a equipe de TI', S['capa2']), Spacer(1, 12 * mm)]
    st += md("""## Conteúdo do pacote
- **Parte 1 — Checklist de instalação** (requisitos, passos, verificação, operação, problemas)
- **Parte 2 — Guia completo** (arquitetura, configuração, Apache/IIS/Nginx, migração, modelo de segurança, matriz de acesso, rotas, banco, validação, referências)
- **Parte 3 — Telas** do novo acesso (entrada, troca obrigatória, Área Admin)
- **Arquivos:** pasta `sisi/` (index.html, api.php, config.exemplo.php, schema.sql, .htaccess, web.config, vendor/, uploads/), `servidores/nginx_sisi.conf`, `teste_api.sh`, `testes/`""", W)
    st += [Spacer(1, 10 * mm), Paragraph('Emitido em 10/10/2026 · pacote u2.30-srv · validado em MariaDB 10.11 + PHP 8.3 + Apache 2.4 + Chromium.<br/>'
           'Ao concluir, informar ao gestor do painel: o endereço final e o <b>token de instalação</b> definido no config.php (por canal seguro).', S['corpo']), PageBreak()]
    for arq, titulo in (('LEIA-ME_TI.md', 'Parte 1 — Checklist de instalação'), ('README_IMPLANTACAO.md', 'Parte 2 — Guia completo de implantação')):
        txt = open(os.path.join(RAIZ, arq), encoding='utf-8').read()
        txt = re.sub(r'^# .*\n', '', txt, count=1)
        st.append(Paragraph(titulo, S['h1'])); st += md(txt, W); st.append(PageBreak())
    st.append(Paragraph('Parte 3 — Telas do novo acesso', S['h1']))
    telas = [('1_entrada.png', 'Entrada: usuário e senha (o perfil é reconhecido pela API).'),
             ('2_troca_obrigatoria.png', 'Primeiro acesso: criação obrigatória da senha pessoal.'),
             ('3_cartao_servidor.png', 'Área Admin → Sincronização: servidor detectado automaticamente.'),
             ('3b_migracao.png', 'Migração única do estado e dos usuários do JSONBin.'),
             ('4_lista_usuarios.png', 'Usuários: login, situação da senha e último acesso.')]
    for nome, leg in telas:
        p = os.path.join(AQUI, 'telas', nome)
        if not os.path.exists(p): continue
        im = Image(p); r = im.imageHeight / float(im.imageWidth); w = min(W, im.imageWidth * 0.75); im.drawWidth = w; im.drawHeight = w * r
        if im.drawHeight > 105 * mm: im.drawHeight = 105 * mm; im.drawWidth = im.drawHeight / r
        st.append(KeepTogether([im, Paragraph('<i>' + inline(leg) + '</i>', ParagraphStyle('leg', parent=S['corpo'], textColor=CINZA, fontSize=8)), Spacer(1, 6)]))
    doc.build(st); print('PDF gerado:', SAIDA)

if __name__ == '__main__':
    main()

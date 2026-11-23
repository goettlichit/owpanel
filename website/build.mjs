// Builds the OWPanel website and documentation into static files in dist/.
//
//   npm install && npm run build
//
// Content lives in content/<lang>/: site.json holds the texts of the start
// page and the frame, docs/*.md the pages of the documentation. Settings
// (address, imprint, privacy policy) come from site.json in this folder;
// site.local.json (not in the repository) overrides them for test builds.
// Everything the pages need is served from the site itself: no CDN, no
// external fonts (docs/DESIGN.md, rule 1).

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import MarkdownIt from 'markdown-it';

const root = path.dirname(fileURLToPath(import.meta.url));
const dist = path.join(root, 'dist');
const languages = ['de', 'en'];
const defaultLanguage = 'de';

// Settings ------------------------------------------------------------------

const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'));
const config = {
    ...readJson(path.join(root, 'site.json')),
    ...(fs.existsSync(path.join(root, 'site.local.json')) ? readJson(path.join(root, 'site.local.json')) : {}),
};
const baseUrl = String(config.base_url || '').replace(/\/+$/, '');
const version = readJson(path.join(root, 'package.json')).version ?? '';

// Helpers -------------------------------------------------------------------

const escape = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#39;');

// Anchors that read well in both languages: "Über uns" -> "ueber-uns".
const slugify = (text) => String(text).toLowerCase()
    .replaceAll('ä', 'ae').replaceAll('ö', 'oe').replaceAll('ü', 'ue').replaceAll('ß', 'ss')
    .normalize('NFKD').replace(/[̀-ͯ]/g, '')
    .replace(/<[^>]+>/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

const prefix = (lang) => (lang === defaultLanguage ? '' : `/${lang}`);
const pagePath = (lang, rest = '/') => `${prefix(lang)}${rest}`;
const icon = (name, extra = '') => `<i class="ti ti-${escape(name)}${extra ? ' ' + extra : ''}" aria-hidden="true"></i>`;

function write(relative, content) {
    const file = path.join(dist, relative);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, content);
}

function copy(from, to, filter = () => true) {
    const source = path.join(root, from);
    const target = path.join(dist, to);
    if (fs.statSync(source).isDirectory()) {
        for (const entry of fs.readdirSync(source)) {
            if (filter(entry)) copy(path.join(from, entry), path.join(to, entry), filter);
        }
        return;
    }
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.copyFileSync(source, target);
}

// Markdown ------------------------------------------------------------------

function markdown() {
    const md = new MarkdownIt({ html: true, linkify: false, typographer: false });

    // Headings get anchors; tables Tabler's classes.
    md.renderer.rules.heading_open = (tokens, i, options, env, self) => {
        const token = tokens[i];
        if (['h2', 'h3'].includes(token.tag)) {
            const text = tokens[i + 1].content;
            let id = slugify(text) || 'abschnitt';
            env.ids ??= new Set();
            for (let n = 2; env.ids.has(id); n++) id = `${slugify(text)}-${n}`;
            env.ids.add(id);
            token.attrSet('id', id);
            if (token.tag === 'h2') (env.toc ??= []).push({ id, text });
        }
        return self.renderToken(tokens, i, options);
    };
    md.renderer.rules.table_open = () => '<div class="table-responsive"><table class="table table-vcenter">\n';
    md.renderer.rules.table_close = () => '</table></div>\n';

    // Links to other pages: "docs:installation" or "docs:installation#anker".
    const linkOpen = md.renderer.rules.link_open ?? ((tokens, i, options, env, self) => self.renderToken(tokens, i, options));
    md.renderer.rules.link_open = (tokens, i, options, env, self) => {
        const href = tokens[i].attrGet('href') ?? '';
        if (href.startsWith('docs:')) {
            const [slug, anchor] = href.slice(5).split('#');
            tokens[i].attrSet('href', pagePath(env.lang, `/docs/${slug}/`) + (anchor ? `#${anchor}` : ''));
        } else if (/^https?:/.test(href)) {
            tokens[i].attrSet('rel', 'noopener noreferrer');
        }
        return linkOpen(tokens, i, options, env, self);
    };

    return md;
}

// Notes: lines "::: info", "::: warning" or "::: tip" up to ":::".
function containers(source) {
    const tones = { info: 'info', tip: 'success', warning: 'warning', danger: 'danger' };
    const icons = { info: 'info-circle', tip: 'bulb', warning: 'alert-triangle', danger: 'alert-octagon' };
    return source.replace(/^::: *(info|tip|warning|danger)\s*\n([\s\S]*?)^:::\s*$/gm, (_, kind, body) =>
        `<div class="alert alert-${tones[kind]} ow-note" role="note">\n<div class="ow-note-icon">${icon(icons[kind])}</div>\n<div class="ow-note-body">\n\n${body}\n</div>\n</div>\n`);
}

function frontMatter(source) {
    const match = source.match(/^---\n([\s\S]*?)\n---\n/);
    if (!match) return { meta: {}, body: source };
    const meta = {};
    for (const line of match[1].split('\n')) {
        const [key, ...rest] = line.split(':');
        if (key.trim()) meta[key.trim()] = rest.join(':').trim();
    }
    return { meta, body: source.slice(match[0].length) };
}

// Content -------------------------------------------------------------------

const md = markdown();
// Readers of the documentation (E-178): administrators run OWPanel, customers
// only see the customer area. Customer pages say "audience: customer".
const audiences = ['admin', 'customer'];
const site = Object.fromEntries(languages.map((lang) => [lang, readJson(path.join(root, 'content', lang, 'site.json'))]));

const docs = Object.fromEntries(languages.map((lang) => {
    const folder = path.join(root, 'content', lang, 'docs');
    const pages = fs.readdirSync(folder).filter((f) => f.endsWith('.md')).sort().map((file) => {
        const { meta, body } = frontMatter(fs.readFileSync(path.join(folder, file), 'utf8'));
        const slug = meta.slug || file.replace(/^\d+-/, '').replace(/\.md$/, '');
        const env = { lang };
        const html = md.render(containers(body), env);
        const audience = meta.audience || 'admin';
        if (!audiences.includes(audience)) throw new Error(`${lang}/docs/${file}: unknown audience "${audience}"`);
        return { slug, title: meta.title || slug, description: meta.description || '', group: meta.group || '', audience, icon: meta.icon || 'file-text', html, toc: env.toc ?? [] };
    });
    // Pages for administrators first, then those for customers (E-178).
    pages.sort((a, b) => audiences.indexOf(a.audience) - audiences.indexOf(b.audience));
    return [lang, pages];
}));

// /docs/admin/ and /docs/customer/ list the pages of one audience; no page may take these names.
for (const lang of languages) {
    const taken = docs[lang].find((page) => audiences.includes(page.slug));
    if (taken) throw new Error(`${lang}/docs: the slug "${taken.slug}" is reserved for an overview page`);
}

// Both languages must have the same pages, so the language switch always leads somewhere.
const slugs = (lang) => docs[lang].map((page) => page.slug).join(',');
if (slugs('de') !== slugs('en')) {
    throw new Error(`The documentation differs between de (${slugs('de')}) and en (${slugs('en')}).`);
}

// Frame ---------------------------------------------------------------------

const logo = (size = 44) => `<svg class="ow-logo" width="${size}" height="${size}" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
    <circle cx="32" cy="32" r="30.5" fill="var(--tblr-bg-surface)" stroke="var(--tblr-border-color)" stroke-width="1.5"/>
    <text x="32" y="33" text-anchor="middle" font-family="'Inter Variable', Inter, system-ui, sans-serif" font-size="21" font-weight="600" fill="var(--tblr-body-color)">ow</text>
    <text x="32" y="46" text-anchor="middle" font-family="'Inter Variable', Inter, system-ui, sans-serif" font-size="10.5" font-weight="500" fill="var(--tblr-primary)">panel</text>
</svg>`;

function layout({ lang, title, description, rest, active, body, alternate = rest }) {
    const t = site[lang];
    const other = languages.find((l) => l !== lang);
    const url = (l, r) => `${baseUrl}${pagePath(l, r)}`;
    const nav = [
        { key: 'home', href: pagePath(lang, '/'), icon: 'home' },
        { key: 'features', href: pagePath(lang, '/#funktionen'), icon: 'sparkles' },
        { key: 'docs', href: pagePath(lang, '/docs/'), icon: 'book' },
        { key: 'roadmap', href: pagePath(lang, '/#roadmap'), icon: 'route' },
    ];
    const source = config.source_public && config.source_url
        ? `<a class="ow-mainnav-link ms-auto" href="${escape(config.source_url)}" rel="noopener noreferrer">${icon('brand-github')}<span>${escape(t.nav.source)}</span></a>`
        : '';
    const legal = [
        config.imprint_url ? `<li class="list-inline-item"><a class="link-secondary" href="${escape(config.imprint_url)}">${escape(t.footer.imprint)}</a></li>` : '',
        config.privacy_url ? `<li class="list-inline-item"><a class="link-secondary" href="${escape(config.privacy_url)}">${escape(t.footer.privacy)}</a></li>` : '',
    ].join('');

    return `<!doctype html>
<html lang="${lang}" data-theme-preference="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>${escape(title)}</title>
    <meta name="description" content="${escape(description)}">
    ${baseUrl ? `<link rel="canonical" href="${escape(url(lang, rest))}">` : ''}
    ${baseUrl ? languages.map((l) => `<link rel="alternate" hreflang="${l}" href="${escape(url(l, alternate))}">`).join('\n    ') : ''}
    <meta name="color-scheme" content="light dark">
    <script src="/assets/theme.js"></script>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/vendor/tabler.min.css">
    <link rel="stylesheet" href="/assets/vendor/icons/tabler-icons.min.css">
    <link rel="stylesheet" href="/assets/vendor/inter/wght.css">
    <link rel="stylesheet" href="/assets/site.css">
</head>
<body>
    <a class="visually-hidden-focusable ow-skip" href="#inhalt">${escape(t.skip)}</a>
    <header>
        <div class="ow-topbar">
            <div class="ow-bar">
                <a class="ow-brand" href="${pagePath(lang, '/')}" aria-label="${escape(t.home)}">
                    ${logo()}
                    <span class="ow-brand-name">Open Web Panel</span>
                </a>
                <div class="ow-topbar-end">
                    <a class="btn btn-ghost-primary ow-icon-button" href="${pagePath(other, alternate)}" hreflang="${other}" lang="${other}" title="${escape(t.language.switch)}" aria-label="${escape(t.language.switch)}">
                        <span class="ow-lang">${other.toUpperCase()}</span>
                    </a>
                    <button type="button" class="btn btn-ghost-primary ow-icon-button" data-theme-toggle title="${escape(t.theme.switch)}" aria-label="${escape(t.theme.switch)}"
                        data-label-system="${escape(t.theme.system)}" data-label-light="${escape(t.theme.light)}" data-label-dark="${escape(t.theme.dark)}">
                        <i class="ti ti-device-desktop" data-theme-icon aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
        <nav class="ow-mainnav" aria-label="${escape(t.nav.main)}">
            <div class="ow-bar">
                ${nav.map((item) => `<a class="ow-mainnav-link${item.key === active ? ' active' : ''}" href="${item.href}"${item.key === active ? ' aria-current="page"' : ''}>${icon(item.icon)}<span>${escape(t.nav[item.key])}</span></a>`).join('\n                ')}
                ${source}
            </div>
        </nav>
    </header>
    <main id="inhalt">
${body}
    </main>
    <footer class="ow-footer">
        <div class="ow-footer-columns">
            <section>
                <h2 class="ow-footer-title">${icon('info-circle')}${escape(t.footer.about_title)}</h2>
                <p class="text-secondary mb-0">${escape(t.footer.about)}</p>
            </section>
            <section>
                <h2 class="ow-footer-title">${icon('lifebuoy')}${escape(t.footer.help_title)}</h2>
                <ul class="list-unstyled mb-0">
                    <li><a class="link-secondary" href="${pagePath(lang, '/docs/')}">${escape(t.nav.docs)}</a></li>
                    <li><a class="link-secondary" href="${pagePath(lang, '/docs/installation/')}">${escape(t.footer.install)}</a></li>
                    ${config.source_public && config.source_url ? `<li><a class="link-secondary" href="${escape(config.source_url)}/issues" rel="noopener noreferrer">${escape(t.footer.issues)}</a></li>` : ''}
                </ul>
            </section>
            <section>
                <h2 class="ow-footer-title">${icon('scale')}${escape(t.footer.license_title)}</h2>
                <p class="text-secondary mb-0">${escape(t.footer.license)}</p>
            </section>
        </div>
        <div class="ow-footer-bottom">
            <ul class="list-inline list-inline-dots mb-0">
                <li class="list-inline-item">OWPanel by <a class="link-secondary" href="${escape(config.vendor_url)}" rel="noopener noreferrer">goettlichIT</a></li>
                <li class="list-inline-item">AGPL-3.0-or-later</li>
                ${config.source_public && config.source_url ? `<li class="list-inline-item"><a class="link-secondary" href="${escape(config.source_url)}" rel="noopener noreferrer">${escape(t.footer.source)}</a></li>` : ''}
                ${legal}
            </ul>
        </div>
    </footer>
    <script src="/assets/site.js" defer></script>
</body>
</html>
`;
}

// Start page ----------------------------------------------------------------

function startPage(lang) {
    const t = site[lang];
    const h = t.home;
    const card = (f) => `
                <div class="col-sm-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-status-top bg-blue"></div>
                        <div class="card-body">
                            <div class="ow-feature-icon">${icon(f.icon)}</div>
                            <h3 class="card-title mb-2">${escape(f.title)}</h3>
                            <p class="text-secondary mb-0">${escape(f.text)}</p>
                        </div>
                    </div>
                </div>`;
    const tones = { done: 'green', next: 'blue', planned: 'secondary' };
    const milestone = (m) => `
                    <li class="ow-milestone">
                        <span class="badge bg-${tones[m.state]}-lt">${escape(h.roadmap.states[m.state])}</span>
                        <div>
                            <strong>${escape(m.title)}</strong>
                            <div class="text-secondary">${escape(m.text)}</div>
                        </div>
                    </li>`;

    // A small, static view of the panel, built from the same components.
    const preview = `
            <div class="ow-preview" aria-hidden="true">
                <div class="card">
                    <div class="card-status-top bg-green"></div>
                    <div class="card-header"><h3 class="card-title">${escape(h.preview.title)}</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table mb-0">
                            <thead><tr><th>${escape(h.preview.site)}</th><th>${escape(h.preview.backup)}</th><th>${escape(h.preview.last)}</th></tr></thead>
                            <tbody>
                                ${h.preview.rows.map((r) => `<tr>
                                    <td><span class="fw-medium">${escape(r.domain)}</span><div class="small text-secondary">${icon('lock', 'text-green')} HTTP/3 · PHP 8.5</div></td>
                                    <td><span class="badge bg-${r.manual ? 'green' : 'blue'}-lt">${escape(r.manual ? h.preview.manual : h.preview.automatic)}</span></td>
                                    <td><span class="badge bg-${r.running ? 'blue' : 'green'}-lt status-${r.running ? 'running' : 'succeeded'}">${icon(r.running ? 'loader-2' : 'circle-check', 'me-1')}${escape(r.running ? h.preview.running : h.preview.succeeded)}</span></td>
                                </tr>`).join('\n                                ')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>`;

    const body = `
        <section class="ow-hero">
            <div class="ow-wrap ow-hero-grid">
                <div>
                    <span class="badge bg-orange-lt mb-3">${icon('flask', 'me-1')}${escape(h.status_badge)}</span>
                    <h1 class="ow-hero-title">${escape(h.title)}</h1>
                    <p class="ow-hero-lead">${escape(h.lead)}</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-primary btn-lg" href="${pagePath(lang, '/docs/')}">${icon('book', 'me-1')}${escape(h.cta_docs)}</a>
                        <a class="btn btn-outline-primary btn-lg" href="${pagePath(lang, '/docs/installation/')}">${icon('terminal-2', 'me-1')}${escape(h.cta_install)}</a>
                    </div>
                    <p class="small text-secondary mt-3 mb-0">${escape(h.status_text)}</p>
                </div>
                ${preview}
            </div>
        </section>

        <section class="ow-section" id="funktionen" aria-labelledby="funktionen-titel">
            <div class="ow-wrap">
                <h2 class="ow-section-heading" id="funktionen-titel">${escape(h.features_title)}</h2>
                <p class="ow-section-lead">${escape(h.features_lead)}</p>
                <div class="row row-cards">${h.features.map(card).join('')}
                </div>
            </div>
        </section>

        <section class="ow-section ow-section-alt" aria-labelledby="ablauf-titel">
            <div class="ow-wrap">
                <h2 class="ow-section-heading" id="ablauf-titel">${escape(h.steps_title)}</h2>
                <div class="row row-cards">
                    ${h.steps.map((s, i) => `
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="ow-step">${i + 1}</div>
                                <h3 class="card-title mb-2">${escape(s.title)}</h3>
                                <p class="text-secondary mb-0">${escape(s.text)}</p>
                            </div>
                        </div>
                    </div>`).join('')}
                </div>
            </div>
        </section>

        <section class="ow-section" id="roadmap" aria-labelledby="roadmap-titel">
            <div class="ow-wrap ow-two">
                <div>
                    <h2 class="ow-section-heading" id="roadmap-titel">${escape(h.roadmap.title)}</h2>
                    <p class="ow-section-lead">${escape(h.roadmap.lead)}</p>
                    <ol class="ow-milestones">${h.roadmap.items.map(milestone).join('')}
                    </ol>
                </div>
                <div>
                    <h2 class="ow-section-heading">${escape(h.stack.title)}</h2>
                    <div class="card">
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table mb-0">
                                <tbody>
                                    ${h.stack.rows.map(([k, v]) => `<tr><th scope="row" class="text-secondary fw-normal">${escape(k)}</th><td>${escape(v)}</td></tr>`).join('\n                                    ')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="ow-section ow-section-alt" aria-labelledby="lizenz-titel">
            <div class="ow-wrap">
                <div class="card">
                    <div class="card-status-top bg-green"></div>
                    <div class="card-body d-flex flex-wrap align-items-center gap-3">
                        <div class="ow-feature-icon mb-0">${icon('heart-handshake')}</div>
                        <div class="flex-fill">
                            <h2 class="card-title mb-1" id="lizenz-titel">${escape(h.open.title)}</h2>
                            <p class="text-secondary mb-0">${escape(h.open.text)}</p>
                        </div>
                        <a class="btn btn-outline-primary" href="${pagePath(lang, '/docs/')}">${escape(h.cta_docs)}</a>
                    </div>
                </div>
            </div>
        </section>`;

    return layout({ lang, title: `OWPanel – ${h.title}`, description: h.lead, rest: '/', active: 'home', body });
}

// Documentation ---------------------------------------------------------------

// The pages of one language by audience, and within it by group.
function sections(lang) {
    return audiences.map((audience) => {
        const groups = [];
        for (const page of docs[lang].filter((p) => p.audience === audience)) {
            let group = groups.find((g) => g.name === page.group);
            if (!group) groups.push(group = { name: page.group, pages: [] });
            group.pages.push(page);
        }
        return { audience, title: site[lang].docs.audiences[audience], groups };
    }).filter((s) => s.groups.length > 0);
}

// The sidebar shows only the pages for the same readers (E-183), with a way
// back to the choice between both documentations.
function sidebar(lang, audience, current) {
    const t = site[lang];
    const section = sections(lang).find((s) => s.audience === audience);
    return `
            <aside class="ow-sidebar">
                <nav aria-label="${escape(t.docs.menu)}">
                    <a class="ow-sidebar-back" href="${pagePath(lang, '/docs/')}">${icon('arrow-left')}<span>${escape(t.docs.choose_other)}</span></a>
                    <a class="ow-sidebar-audience${current === null ? ' active' : ''}" href="${pagePath(lang, `/docs/${audience}/`)}"${current === null ? ' aria-current="page"' : ''}>${escape(section.title)}</a>
                    ${section.groups.map((g) => `${g.name ? `
                    <div class="ow-sidebar-group">${escape(g.name)}</div>` : ''}
                    <div class="nav flex-column">
                        ${g.pages.map((p) => `<a class="nav-link${p.slug === current ? ' active' : ''}" href="${pagePath(lang, `/docs/${p.slug}/`)}"${p.slug === current ? ' aria-current="page"' : ''}>${icon(p.icon)}<span>${escape(p.title)}</span></a>`).join('\n                        ')}
                    </div>`).join('')}
                </nav>
            </aside>`;
}

function docPage(lang, index) {
    const t = site[lang];
    const page = docs[lang][index];
    // The pager stays among the pages for the same readers.
    const same = (p) => p && p.audience === page.audience ? p : undefined;
    const prev = same(docs[lang][index - 1]);
    const next = same(docs[lang][index + 1]);
    const toc = page.toc.length > 1 ? `
            <aside class="ow-toc" aria-label="${escape(t.docs.on_this_page)}">
                <div class="ow-sidebar-group">${escape(t.docs.on_this_page)}</div>
                <nav class="nav flex-column">
                    ${page.toc.map((h) => `<a class="nav-link" href="#${h.id}">${escape(h.text)}</a>`).join('\n                    ')}
                </nav>
            </aside>` : '';
    const body = `
        <div class="ow-body">
${sidebar(lang, page.audience, page.slug)}
            <div class="ow-content">
                <div class="page-header mb-3">
                    <div class="page-pretitle"><a class="link-secondary" href="${pagePath(lang, `/docs/${page.audience}/`)}">${escape(t.docs.audiences[page.audience])}</a>${page.group ? ` · ${escape(page.group)}` : ''}</div>
                    <h1 class="page-title">${escape(page.title)}</h1>
                </div>
                <div class="card">
                    <div class="card-status-top bg-blue"></div>
                    <article class="card-body ow-prose">
${page.html}
                    </article>
                </div>
                <nav class="ow-pager" aria-label="${escape(t.docs.pager)}">
                    ${prev ? `<a class="btn btn-outline-secondary" href="${pagePath(lang, `/docs/${prev.slug}/`)}">${icon('arrow-left', 'me-1')}${escape(prev.title)}</a>` : '<span></span>'}
                    ${next ? `<a class="btn btn-outline-primary" href="${pagePath(lang, `/docs/${next.slug}/`)}">${escape(next.title)}${icon('arrow-right', 'ms-1')}</a>` : ''}
                </nav>
            </div>${toc}
        </div>`;
    return layout({ lang, title: `${page.title} – ${t.docs.title} – OWPanel`, description: page.description, rest: `/docs/${page.slug}/`, active: 'docs', body });
}

// /docs/: first the choice between the documentation for administrators and
// for customers (E-183), so customers do not land among server topics.
function docsIndex(lang) {
    const t = site[lang];
    const icons = { admin: 'server-cog', customer: 'user' };
    const body = `
        <div class="ow-body">
            <div class="ow-content ow-docs-choice">
                <div class="page-header mb-4">
                    <h1 class="page-title">${escape(t.docs.title)}</h1>
                    <p class="text-secondary mt-1 mb-0">${escape(t.docs.lead)}</p>
                </div>
                <div class="row row-cards">
                    ${sections(lang).map((s) => `
                    <div class="col-md-6">
                        <a class="card card-link h-100" href="${pagePath(lang, `/docs/${s.audience}/`)}">
                            <div class="card-status-top bg-blue"></div>
                            <div class="card-body">
                                <div class="ow-feature-icon">${icon(icons[s.audience])}</div>
                                <h2 class="card-title mb-2">${escape(t.docs.audience_docs[s.audience])}</h2>
                                <p class="text-secondary mb-3">${escape(t.docs.audience_leads[s.audience])}</p>
                                <span class="btn btn-primary">${escape(t.docs.open)}${icon('arrow-right', 'ms-1')}</span>
                            </div>
                        </a>
                    </div>`).join('')}
                </div>
            </div>
        </div>`;
    return layout({ lang, title: `${t.docs.title} – OWPanel`, description: t.docs.lead, rest: '/docs/', active: 'docs', body });
}

// /docs/admin/ and /docs/customer/: the pages for one audience by group.
function audienceIndex(lang, section) {
    const t = site[lang];
    const body = `
        <div class="ow-body">
${sidebar(lang, section.audience, null)}
            <div class="ow-content">
                <div class="page-header mb-3">
                    <div class="page-pretitle"><a class="link-secondary" href="${pagePath(lang, '/docs/')}">${escape(t.docs.title)}</a></div>
                    <h1 class="page-title">${escape(t.docs.audience_docs[section.audience])}</h1>
                    <p class="text-secondary mt-1 mb-0">${escape(t.docs.audience_leads[section.audience])}</p>
                </div>
                ${section.groups.map((g) => `${g.name ? `
                <h2 class="ow-section-title">${escape(g.name)}</h2>` : ''}
                <div class="row row-cards mb-4">
                    ${g.pages.map((p) => `
                    <div class="col-md-6 col-xl-4">
                        <a class="card card-link h-100" href="${pagePath(lang, `/docs/${p.slug}/`)}">
                            <div class="card-status-top bg-blue"></div>
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-2">${icon(p.icon, 'ow-doc-icon')}<h3 class="card-title mb-0">${escape(p.title)}</h3></div>
                                <p class="text-secondary mb-0">${escape(p.description)}</p>
                            </div>
                        </a>
                    </div>`).join('')}
                </div>`).join('')}
            </div>
        </div>`;
    const title = t.docs.audience_docs[section.audience];
    return layout({ lang, title: `${title} – OWPanel`, description: t.docs.audience_leads[section.audience], rest: `/docs/${section.audience}/`, active: 'docs', body });
}

function notFound() {
    const t = site[defaultLanguage];
    const e = site.en;
    const body = `
        <div class="ow-body">
            <div class="ow-content">
                <div class="empty">
                    <div class="empty-header">404</div>
                    <p class="empty-title">${escape(t.not_found.title)}</p>
                    <p class="empty-subtitle text-secondary">${escape(t.not_found.text)} · <span lang="en">${escape(e.not_found.text)}</span></p>
                    <div class="empty-action d-flex gap-2 justify-content-center">
                        <a class="btn btn-primary" href="/">${escape(t.not_found.home)}</a>
                        <a class="btn btn-outline-primary" href="/en/" lang="en">${escape(e.not_found.home)}</a>
                    </div>
                </div>
            </div>
        </div>`;
    return layout({ lang: defaultLanguage, title: `404 – OWPanel`, description: '', rest: '/', active: null, body });
}

// Build ---------------------------------------------------------------------

fs.rmSync(dist, { recursive: true, force: true });

// Assets: Tabler (MIT), Tabler Icons (MIT), Inter (SIL OFL 1.1), with their licences.
copy('node_modules/@tabler/core/dist/css/tabler.min.css', 'assets/vendor/tabler.min.css');
copy('node_modules/@tabler/icons-webfont/dist/tabler-icons.min.css', 'assets/vendor/icons/tabler-icons.min.css');
// Only the font files the icon styles refer to, split by category.
const iconFonts = new Set([...fs.readFileSync(path.join(root, 'node_modules/@tabler/icons-webfont/dist/tabler-icons.min.css'), 'utf8')
    .matchAll(/url\("?\.\/fonts\/([^"?)]+)/g)].map((m) => m[1]));
copy('node_modules/@tabler/icons-webfont/dist/fonts', 'assets/vendor/icons/fonts', (f) => iconFonts.has(f));
copy('node_modules/@fontsource-variable/inter/wght.css', 'assets/vendor/inter/wght.css');
copy('node_modules/@fontsource-variable/inter/files', 'assets/vendor/inter/files', (f) => f.includes('wght-normal') || f.includes('wght-italic'));
copy('node_modules/@tabler/icons-webfont/LICENSE', 'assets/licenses/tabler-icons-LICENSE.txt');
copy('node_modules/@fontsource-variable/inter/LICENSE', 'assets/licenses/inter-OFL.txt');
// @tabler/core ships no licence file; its package names MIT.
write('assets/licenses/README.txt', `Third-party files served by this website:

- Tabler ${readJson(path.join(root, 'node_modules/@tabler/core/package.json')).version} (assets/vendor/tabler.min.css), MIT licence, https://github.com/tabler/tabler
- Tabler Icons ${readJson(path.join(root, 'node_modules/@tabler/icons-webfont/package.json')).version} (assets/vendor/icons), MIT licence, see tabler-icons-LICENSE.txt
- Inter ${readJson(path.join(root, 'node_modules/@fontsource-variable/inter/package.json')).version} (assets/vendor/inter), SIL Open Font License 1.1, see inter-OFL.txt

The website itself: OWPanel by goettlichIT, AGPL-3.0-or-later.
`);
copy('src', 'assets', (f) => !f.startsWith('favicon'));
copy('src/favicon.svg', 'favicon.svg');

const urls = [];
for (const lang of languages) {
    write(path.join(prefix(lang), 'index.html'), startPage(lang));
    write(path.join(prefix(lang), 'docs', 'index.html'), docsIndex(lang));
    urls.push(pagePath(lang, '/'), pagePath(lang, '/docs/'));
    for (const section of sections(lang)) {
        write(path.join(prefix(lang), 'docs', section.audience, 'index.html'), audienceIndex(lang, section));
        urls.push(pagePath(lang, `/docs/${section.audience}/`));
    }
    docs[lang].forEach((page, i) => {
        write(path.join(prefix(lang), 'docs', page.slug, 'index.html'), docPage(lang, i));
        urls.push(pagePath(lang, `/docs/${page.slug}/`));
    });
}
write('404.html', notFound());
write('robots.txt', `User-agent: *\nAllow: /\n${baseUrl ? `Sitemap: ${baseUrl}/sitemap.xml\n` : ''}`);
if (baseUrl) {
    write('sitemap.xml', `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls.map((u) => `  <url><loc>${escape(baseUrl + u)}</loc></url>`).join('\n')}\n</urlset>\n`);
}

const missing = ['base_url', 'imprint_url', 'privacy_url'].filter((key) => !config[key]);
console.log(`Built ${urls.length} pages into dist/${version ? ` (version ${version})` : ''}.`);
if (missing.length) {
    console.warn(`Not set in site.json or site.local.json: ${missing.join(', ')}. Set them before publishing.`);
}

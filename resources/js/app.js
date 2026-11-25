import '../css/app.css';
import { api } from './api.js';
import { bottleIcon, duration, fill, h, inSea, inShore, number, plural, theSea } from './dom.js';
import { Globe } from './globe.js';
import { compass, distanceKm, formatCoords, Ocean } from './ocean.js';
import { toggleSea } from './sound.js';

const $ = (selector) => document.querySelector(selector);
const panel = $('#panel'), readout = $('#readout'), news = $('#news'), finding = $('#finding');
const state = { bottles: [], daysPerMinute: 1, tab: 'ashore', view: 'list', draft: { letter: '', signature: '', glass: 'verde' }, pin: null };
const TABS = { ashore: 'Encalhadas', drifting: 'No mar', mine: 'Minhas', log: 'Diário' };
const GLASS = { verde: 'verde', ambar: 'âmbar', azul: 'azul', claro: 'claro' };

let globe, ocean;

// Tema: dia e noite, como as paletas dos painéis ECDIS de passadiço.
const remembered = (key, value) => {
    try {
        if (value !== undefined) localStorage.setItem(key, value);
        return localStorage.getItem(key);
    } catch { return null; }
};
function setTheme(theme) {
    document.documentElement.dataset.theme = theme;
    $('#theme').textContent = theme === 'noite' ? 'Modo dia' : 'Modo noite';
    globe?.readTheme();
}
const hour = new Date().getHours();
setTheme(remembered('deriva.tema') ?? (hour >= 19 || hour < 6 ? 'noite' : 'dia'));
$('#theme').onclick = () => {
    const next = document.documentElement.dataset.theme === 'noite' ? 'dia' : 'noite';
    remembered('deriva.tema', next);
    setTheme(next);
};
$('#sound').onclick = async (e) => {
    const on = e.currentTarget.getAttribute('aria-pressed') !== 'true';
    e.currentTarget.setAttribute('aria-pressed', on);
    e.currentTarget.textContent = on ? 'Calar o mar' : 'Ouvir o mar';
    await toggleSea(on);
};

const belongs = (b, tab) => tab === 'mine' ? b.mine || b.forMe || b.status === 'opened' : b.status === tab;
const title = (b) => b.status === 'ashore' ? b.shore ?? 'Costa sem nome'
    : b.status === 'opened' ? `Aberta ${inShore(b.shore)}`
    : `À deriva desde ${theSea(b.launchedSea)}`;
const subtitle = (b) => b.status === 'drifting'
    ? `${duration(b.days)} no mar, ${number(b.km)} km`
    : `${duration(b.days)}, ${number(b.km)} km desde ${theSea(b.launchedSea)}`;

function renderList() {
    state.view = 'list';
    const focus = panel.contains(document.activeElement) && document.activeElement.dataset?.tab;
    const tabs = h('div', { class: 'tabs', role: 'tablist', 'aria-label': 'Garrafas' }, Object.entries(TABS).map(([key, label]) =>
        h('button', { type: 'button', role: 'tab', 'aria-selected': String(state.tab === key), 'data-tab': key, onclick: () => { state.tab = key; renderList(); } },
            label, key === 'log' ? null : h('span', { class: 'count' }, String(state.bottles.filter((b) => belongs(b, key)).length)))));
    if (state.tab === 'log') {
        fill(panel, tabs, h('div', { class: 'log', id: 'log' }, h('p', { class: 'muted' }, 'Lendo o diário…')));
        renderLog();
    } else {
        const rows = state.bottles.filter((b) => belongs(b, state.tab))
            .sort((a, b) => Number(b.forMe) - Number(a.forMe) || b.days - a.days);
        fill(panel, tabs, rows.length
            ? h('ul', { class: 'rows' }, rows.map((b) => h('li', {}, h('button', { type: 'button', class: 'row', onclick: () => select(b.id) },
                bottleIcon(b.glass, 30),
                h('span', { class: 'row-text' }, h('strong', {}, title(b)), h('span', {}, subtitle(b))),
                b.forMe && b.status === 'ashore' ? h('span', { class: 'tag' }, 'resposta a você') : b.isReply ? h('span', { class: 'tag quiet' }, 'resposta') : null))))
            : h('p', { class: 'empty' }, {
                ashore: 'Nenhuma garrafa na areia agora. A maré trará outras.',
                drifting: 'O mar está vazio. Lance a primeira garrafa.',
                mine: 'Você ainda não lançou nem abriu nenhuma garrafa.',
            }[state.tab]));
    }
    if (focus) panel.querySelector(`[data-tab="${focus}"]`)?.focus();
}

async function renderLog() {
    const log = await api.logbook().catch(() => null);
    const root = $('#log');
    if (!root) return;
    if (!log) return root.replaceChildren(h('p', { class: 'error' }, 'Não consegui ler o diário. Tente de novo.'));
    const most = Math.max(1, ...log.ages.map((a) => a.n));
    root.replaceChildren(
        h('dl', { class: 'facts' },
            fact('Percorridos por todas', `${number(log.km)} km`),
            fact('Viagem mais longa', duration(log.longestDays)),
            fact('Garrafas lançadas', number(log.drifting + log.ashore + log.opened)),
            fact('Já abertas', number(log.opened))),
        h('h2', {}, 'Tempo de mar das que seguem à deriva'),
        h('ul', { class: 'bars' }, log.ages.map((a) => h('li', {},
            h('span', {}, a.label), h('span', { class: 'bar' }, h('i', { style: `width: ${a.n / most * 100}%` })), h('b', {}, String(a.n))))),
        h('h2', {}, 'Mais viajadas'),
        h('ol', { class: 'ranked' }, log.farthest.map((b) => h('li', {},
            bottleIcon(b.glass, 22),
            h('span', {}, b.shore ? `Desde ${theSea(b.from)}, encalhada ${inShore(b.shore)}` : `Desde ${theSea(b.from)}, ainda no mar`),
            b.id && state.bottles.some((x) => x.id === b.id)
                ? h('button', { type: 'button', class: 'link', onclick: () => select(b.id) }, `${number(b.km)} km`) : h('b', {}, `${number(b.km)} km`)))),
        h('h2', {}, 'Costas que mais recebem'),
        h('ul', { class: 'ranked' }, log.shores.map((s) => h('li', {}, h('span', {}, s.name), h('b', {}, String(s.n))))));
}

const fact = (label, value) => h('div', {}, h('dt', {}, label), h('dd', {}, value));
const back = (label = 'Voltar à lista') => h('button', { type: 'button', class: 'link back', onclick: deselect }, label);

function deselect() {
    globe.selected = globe.track = globe.forecast = globe.pin = null;
    globe.picking = false;
    document.body.classList.remove('picking');
    history.replaceState(null, '', location.pathname);
    renderList();
}

async function select(id) {
    const known = state.bottles.find((b) => b.id === id);
    globe.forecast = globe.pin = null;
    globe.picking = false;
    document.body.classList.remove('picking');
    globe.selected = id;
    globe.track = null;
    state.view = 'detail';
    history.replaceState(null, '', `#garrafa-${id}`);
    fill(panel, back(), h('p', { class: 'muted' }, 'Aproximando a luneta…'));
    if (known) globe.flyTo(known.lat, known.lng, Math.max(1, Math.min(globe.view.zoom, 2)));
    try {
        const b = await api.bottle(id);
        if (globe.selected !== id) return;
        if (!state.bottles.some((x) => x.id === id)) state.bottles.push(b);
        if (!known) globe.flyTo(b.lat, b.lng);
        globe.track = b.track;
        globe.dirty = true;
        renderDetail(b);
    } catch (e) {
        fill(panel, back(), h('p', { class: 'error' }, e.message));
    }
}

function renderDetail(b) {
    const name = { drifting: 'À deriva', ashore: `Encalhada ${inShore(b.shore)}`, opened: `Aberta ${inShore(b.shore)}` }[b.status];
    const message = h('p', { class: 'error', role: 'alert', hidden: true });
    const openButton = h('button', { type: 'button', class: 'primary', onclick: async () => {
        openButton.disabled = true;
        try {
            const opened = await api.open(b.id);
            await refresh();
            renderDetail(opened);
            showLetter(opened, true);
        } catch (e) {
            message.textContent = e.message;
            message.hidden = false;
            openButton.disabled = false;
        }
    } }, 'Abrir a garrafa');

    fill(panel, 
        back(),
        h('div', { class: 'detail-head' }, bottleIcon(b.glass, 54), h('div', {},
            h('h2', { class: 'detail-title', tabindex: '-1' }, name),
            h('p', { class: 'muted' }, formatCoords(b.lat, b.lng)))),
        h('dl', { class: 'facts' },
            fact('Partiu de', b.launchedSea),
            b.status === 'drifting' ? fact('Agora em', b.sea) : fact('Chegou a', b.shore ?? 'costa sem nome'),
            fact('Tempo no mar', duration(b.days)),
            fact('Percurso', `${number(b.km)} km`),
            fact('Em linha reta', `${number(b.straightKm)} km`),
            b.status === 'drifting' ? fact('Deriva', `${Math.hypot(b.u, b.v).toFixed(2).replace('.', ',')} m/s para ${compass(b.u, b.v)}`) : fact('Vidro', GLASS[b.glass])),
        b.status === 'drifting' ? h('p', {}, b.mine ? 'Sua carta segue viagem. Quando encalhar, qualquer pessoa no mundo poderá abri-la, mas só uma.' : 'A carta só pode ser lida depois que a garrafa encalhar.') : null,
        b.status === 'ashore' && b.mine ? h('p', {}, 'Sua garrafa chegou. Agora espera por quem passe na praia.') : null,
        b.status === 'ashore' && b.reserved ? h('p', {}, 'É uma resposta. Por um ano de mar, só quem escreveu a primeira carta pode abri-la.') : null,
        b.status === 'ashore' && !b.mine && !b.reserved ? [h('p', {}, 'Ninguém abriu ainda. Quem abrir será a única pessoa a ler esta carta.'), openButton] : null,
        b.status === 'opened' && b.mine && !b.opener ? h('p', {}, b.replyId ? 'Alguém leu sua carta e respondeu. A resposta está no mar, vindo na sua direção ou não.' : 'Alguém encontrou e leu sua carta.') : null,
        b.letter ? h('button', { type: 'button', class: b.status === 'opened' ? 'primary' : 'secondary', onclick: () => showLetter(b) }, b.mine ? 'Reler minha carta' : 'Reler a carta') : null,
        b.replyId && state.bottles.some((x) => x.id === b.replyId) ? h('button', { type: 'button', class: 'secondary', onclick: () => select(b.replyId) }, 'Ver a resposta no mapa') : null,
        b.opener && !b.replyId ? h('button', { type: 'button', class: 'secondary', onclick: () => renderWriting(b) }, 'Responder daqui') : null,
        message);
    panel.querySelector('.detail-title').focus({ preventScroll: true });
}

// A carta
const dialog = $('#letter');
function showLetter(b, firstTime = false) {
    const page = (letter, signature, heading, old) => h('article', { class: old ? 'sheet old' : 'sheet' },
        h('p', { class: 'sheet-from' }, heading),
        letter.split(/\n\s*\n/).map((text) => h('p', {}, text)),
        signature ? h('p', { class: 'sheet-sign' }, signature) : null);
    $('#letter-body').replaceChildren(
        ...b.correspondence.map((c) => page(c.letter, c.signature, `Antes, desde ${theSea(c.from)}`, true)),
        page(b.letter, b.signature, b.status === 'drifting' ? `Lançada ${inSea(b.launchedSea)}` : `Lançada ${inSea(b.launchedSea)}, encalhada ${inShore(b.shore)} depois de ${duration(b.days)}`));
    $('#letter-reply').hidden = !(b.opener && !b.replyId);
    $('#letter-reply').onclick = () => { dialog.close(); renderWriting(b); };
    dialog.classList.toggle('unroll', firstTime);
    dialog.showModal();
    $('#letter-body').scrollTop = 0;
}
$('#letter-close').onclick = () => dialog.close();
dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });

// Escrever: lançar ou responder
function renderWriting(inReplyTo = null) {
    state.view = 'writing';
    globe.selected = inReplyTo?.id ?? null;
    globe.track = null;
    globe.picking = !inReplyTo;
    document.body.classList.toggle('picking', !inReplyTo);
    const d = state.draft;
    const errorFor = {};
    const error = (key) => errorFor[key] = h('p', { class: 'error', id: `error-${key}`, role: 'alert', hidden: true });
    const left = h('span', { class: 'muted', id: 'left' });
    const count = () => { left.textContent = plural(1500 - d.letter.length, 'caractere livre', 'caracteres livres'); };
    const submit = h('button', { type: 'submit', class: 'primary' }, inReplyTo ? 'Lançar a resposta' : 'Lançar ao mar');

    const form = h('form', { class: 'writing', novalidate: true, onsubmit: async (e) => {
        e.preventDefault();
        for (const el of Object.values(errorFor)) el.hidden = true;
        const fail = (key, text) => { const el = errorFor[key] ?? errorFor.letter; el.textContent = text; el.hidden = false; };
        if (d.letter.trim().length < 3) return fail('letter', 'A garrafa está vazia. Escreva alguma coisa.');
        if (!inReplyTo && !state.pin) return fail('lat', 'Toque no mar, no mapa, para escolher de onde ela parte.');
        submit.disabled = true;
        try {
            const body = { letter: d.letter.trim(), signature: d.signature.trim() || null, glass: d.glass };
            const bottle = inReplyTo ? await api.reply(inReplyTo.id, body) : await api.launch({ ...body, ...state.pin });
            state.draft = { letter: '', signature: '', glass: d.glass };
            state.pin = null;
            globe.forecast = globe.pin = null;
            globe.picking = false;
            document.body.classList.remove('picking');
            await refresh();
            globe.ripple(bottle.lat, bottle.lng);
            announce('Sua garrafa está no mar.');
            select(bottle.id);
        } catch (e) {
            const keys = Object.entries(e.fields ?? {});
            if (keys.length) for (const [key, [text]] of keys) fail(key === 'lng' ? 'lat' : key, text);
            else fail('letter', e.message);
            submit.disabled = false;
        }
    } },
        h('label', { for: 'letter-text' }, inReplyTo ? 'Sua resposta' : 'Sua carta'),
        h('textarea', { id: 'letter-text', rows: '5', maxlength: '1500', 'aria-describedby': 'left error-letter', oninput: (e) => { d.letter = e.target.value; count(); } }),
        left, error('letter'),
        h('label', { for: 'signature' }, 'Assinatura, se quiser'),
        h('input', { id: 'signature', maxlength: '60', autocomplete: 'off', value: d.signature, oninput: (e) => { d.signature = e.target.value; } }),
        error('signature'),
        h('fieldset', { class: 'glasses' }, h('legend', {}, 'Vidro'), Object.entries(GLASS).map(([key, label]) =>
            h('label', {}, h('input', { type: 'radio', name: 'glass', value: key, checked: d.glass === key, onchange: () => { d.glass = key; } }), bottleIcon(key, 34), label))),
        inReplyTo ? h('p', { class: 'muted' }, `Parte da água aberta mais próxima de onde ela encalhou, ${inShore(inReplyTo.shore)}.`)
            : h('div', { class: 'place' }, h('p', { class: 'place-label' }, 'Ponto de lançamento'), h('p', { id: 'place', 'aria-live': 'polite' }, 'Toque no mar, no mapa.'), h('p', { id: 'forecast', class: 'muted' })),
        error('lat'), submit);

    fill(panel, inReplyTo ? h('button', { type: 'button', class: 'link back', onclick: () => select(inReplyTo.id) }, 'Voltar à garrafa') : back(),
        h('h2', { class: 'detail-title' }, inReplyTo ? 'Responder' : 'Lançar uma garrafa'), form);
    form.querySelector('textarea').value = d.letter;
    count();
    hover(null);
    if (!inReplyTo && state.pin) pick(state.pin);
}

function pick({ lat, lng }) {
    const place = $('#place');
    if (!place) return;
    if (!ocean.isSea(lat, lng)) {
        place.textContent = 'Isso é terra firme. Toque na água.';
        return;
    }
    state.pin = { lat, lng };
    globe.pin = state.pin;
    globe.forecast = ocean.forecast(lat, lng);
    globe.dirty = true;
    const f = globe.forecast, ashore = f.filter((m) => m.ashore).length;
    const far = f.map((m) => distanceKm(state.pin, m.end)).sort((a, b) => a - b)[f.length >> 1];
    place.textContent = formatCoords(lat, lng);
    $('#forecast').textContent = `Previsão para 180 dias: em ${f.length} simulações, ${ashore === 0 ? 'nenhuma encalha' : plural(ashore, 'encalha', 'encalham')}, e metade se afasta mais de ${number(far)} km.`;
    api.here(state.pin).then((here) => { if (state.pin?.lat === lat && $('#place')) $('#place').textContent = `${formatCoords(lat, lng)}, ${here.sea}`; }).catch(() => {});
}

// Leitura sob o cursor, como o rodapé de um ECDIS
let hoverTimer;
const touch = matchMedia('(pointer: coarse)').matches;
function hover(point, near) {
    clearTimeout(hoverTimer);
    if (!point) return readout.replaceChildren(h('span', {}, globe.picking ? 'Toque no mar para escolher de onde ela parte' : touch ? 'Arraste para girar, pince para aproximar' : 'Arraste para girar, role para aproximar'));
    const [u, v] = ocean.current(point.lat, point.lng);
    const speed = Math.hypot(u, v);
    const parts = [h('b', {}, formatCoords(point.lat, point.lng))];
    if (near) parts.push(h('span', {}, `${title(near)}, ${duration(near.days)} no mar`));
    else if (!ocean.isSea(point.lat, point.lng)) parts.push(h('span', {}, 'terra firme'));
    else parts.push(h('span', {}, speed < .005 ? 'sem corrente' : `corrente de ${speed.toFixed(2).replace('.', ',')} m/s para ${compass(u, v)}`));
    readout.replaceChildren(...parts);
    if (!near) hoverTimer = setTimeout(async () => {
        const here = await api.here(point).catch(() => null);
        if (!here) return;
        if (here.sea) readout.append(h('span', {}, here.sea));
        if (here.near[0]) readout.append(h('span', { class: 'wide' }, `garrafa mais próxima a ${number(here.near[0].km)} km`));
    }, 350);
}

function announce(text) {
    news.textContent = text;
    news.classList.remove('fresh');
    void news.offsetWidth;
    news.classList.add('fresh');
}

async function refresh() {
    const sea = await api.sea();
    state.bottles = globe.bottles = sea.bottles;
    state.daysPerMinute = sea.daysPerMinute;
    globe.dirty = true;
    const waiting = sea.bottles.filter((b) => b.status === 'ashore' && !b.mine && !b.reserved).length;
    const forMe = sea.bottles.filter((b) => b.forMe && b.status === 'ashore').length;
    const drifting = sea.bottles.filter((b) => b.status === 'drifting').length;
    finding.textContent = forMe ? `${plural(forMe, 'resposta encalhou', 'respostas encalharam')} para você.`
        : waiting ? `${plural(waiting, 'carta espera', 'cartas esperam')} na areia por quem abra. ${plural(drifting, 'segue', 'seguem')} no mar.`
        : `${plural(drifting, 'garrafa segue', 'garrafas seguem')} no mar.`;
    if (state.view === 'list' && state.tab !== 'log') {
        const scroll = panel.scrollTop;
        renderList();
        panel.scrollTop = scroll;
    }
}

function listen() {
    const events = new EventSource('/mar/eventos'); // reconecta sozinho, com o resume token em Last-Event-ID
    events.onmessage = async ({ data }) => {
        const e = JSON.parse(data);
        const known = state.bottles.find((b) => b.id === e.id);
        await refresh().catch(() => {});
        const b = state.bottles.find((x) => x.id === e.id) ?? known;
        if (b) globe.ripple(b.lat, b.lng);
        if (b?.mine && e.status === 'drifting') return;
        announce({
            drifting: e.isReply ? `Uma resposta acaba de ser lançada ${inSea(e.sea)}.` : `Uma garrafa acaba de ser lançada ${inSea(e.sea)}.`,
            ashore: `${b?.mine ? 'Sua garrafa' : 'Uma garrafa'} encalhou ${inShore(e.shore)} depois de ${duration(e.days)}.`,
            opened: `Alguém abriu uma garrafa ${inShore(e.shore)}.`,
        }[e.status]);
    };
}

$('#launch').onclick = () => renderWriting();

(async () => {
    try {
        ocean = await Ocean.load();
        await document.fonts.load('italic 15px "Fira Sans"').catch(() => {});
        globe = new Globe($('#stage'), ocean);
        globe.handlers = { hover, select, pick };
        hover(null);
        await refresh();
        renderList();
        const wanted = location.hash.match(/^#garrafa-([0-9a-f]{24})$/);
        if (wanted) select(wanted[1]);
        document.body.classList.add('ready');
        listen();
        setInterval(() => document.hidden || refresh().catch(() => {}), 20000);
    } catch (e) {
        fill(panel, h('p', { class: 'error' }, e.message));
    }
})();

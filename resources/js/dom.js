/** h('p', {class: 'x', onclick}, ...filhos). Texto entra sempre como texto: cartas de estranhos nunca viram HTML. */
export function h(tag, props = {}, ...children) {
    const el = tag === 'svg' || tag === 'path' || tag === 'rect'
        ? document.createElementNS('http://www.w3.org/2000/svg', tag) : document.createElement(tag);
    for (const [key, value] of Object.entries(props)) {
        if (value === null || value === undefined || value === false) continue;
        if (key.startsWith('on')) el.addEventListener(key.slice(2), value);
        else el.setAttribute(key, value === true ? '' : value);
    }
    el.append(...children.flat(9).filter((c) => c !== null && c !== undefined && c !== false));
    return el;
}

export const number = (n) => Math.round(n).toLocaleString('pt-BR');
export const plural = (n, one, many) => `${number(n)} ${n === 1 ? one : many}`;

export function duration(days) {
    if (days < 1) return 'menos de um dia';
    if (days < 60) return plural(days, 'dia', 'dias');
    if (days < 365) return plural(Math.floor(days / 30.4), 'mês', 'meses');
    const years = Math.floor(days / 365), months = Math.floor((days % 365) / 30.4);
    return plural(years, 'ano', 'anos') + (months ? ` e ${plural(months, 'mês', 'meses')}` : '');
}

export function bottleIcon(glass, size = 22) {
    return h('svg', { viewBox: '-6 -12 12 21', width: size * .57, height: size, 'aria-hidden': 'true', class: 'bottle-icon' },
        h('path', { d: 'M-1.6-7.6V-4.4C-4-3-4-2-4 0V6Q-4 7.6-2.4 7.6H2.4Q4 7.6 4 6V0C4-2 4-3 1.6-4.4V-7.6Z', style: `fill: var(--glass-${glass}); stroke: var(--ink); stroke-width: 1` }),
        h('rect', { x: -1.6, y: -10.4, width: 3.2, height: 2.8, style: 'fill: var(--cork)' }));
}

export const fill = (el, ...children) => el.replaceChildren(...children.flat(9).filter((c) => c !== null && c !== undefined && c !== false));

const BARE = /^(Portugal|Cuba|Angola|Moçambique|Cabo Verde|São |Santa |Santo |Porto Rico|Madagascar|Marrocos|Israel|Omã|Mianmar|Singapura|Taiwan|Fiji|Samoa|Tonga|Vanuatu|Kiribati|Palau|Nauru|Tuvalu|Niue|Barbados|Trinidad|Antígua|Granada|Malta|Chipre|Timor|Gana|Honduras|El Salvador|Bangladesh|Brunei|Dji|Aruba|Curaçao|Hong Kong|Macau|Montserrat|Anguila|Mónaco|Mônaco|Guam|Jersey|Guernsey|Maurício|Cuba|Saint )/;
const MALE = /^(Quênia|Camboja|Saara)/;

/** "em Portugal", "na Namíbia", "nos Estados Unidos". */
export function inShore(name) {
    if (!name) return 'numa costa sem nome';
    if (BARE.test(name)) return `em ${name}`;
    if (/^(Estados|Países|Emirados|Camarões|Territórios)/.test(name)) return `nos ${name}`;
    if (/^(Ilhas|Terras|Filipinas|Maldivas|Bahamas|Seicheles|Comores|Bermudas|Marianas)/.test(name)) return `nas ${name}`;
    return `${!MALE.test(name) && /^(Ilha|República|Nova|Coreia|Costa|Guiné|Polinésia|África|Arábia|Papua|Terra|Serra|Micronésia|Geórgia)|a$/.test(name) ? 'na' : 'no'} ${name}`;
}

const article = (sea) => /^(Baía|Enseada|Passagem|Grande|Foz)/.test(sea) ? 'a' : 'o';
export const theSea = (sea) => `${article(sea)} ${sea}`;
export const inSea = (sea) => `n${article(sea)} ${sea}`;

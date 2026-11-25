// O mesmo oceano do servidor, lido dos mesmos arquivos.
const COAST_W = 1440, COAST_H = 720, FIELD_W = 360, FIELD_H = 180;
const DEG = Math.PI / 180;
const SECONDS_PER_DAY = 86400, METERS_PER_DEGREE = 111195;

// Espelha App\Ocean\Wind.
const STRESS = [[-90, 0], [-75, -.02], [-65, -.01], [-50, .15], [-38, .07], [-28, -.01], [-15, -.08], [0, -.025],
    [8, -.04], [16, -.08], [28, -.005], [38, .06], [48, .09], [60, .02], [70, -.02], [90, 0]];

export function leeway(lat) {
    for (let k = 1; k < STRESS.length; k++) {
        const [b, tb] = STRESS[k];
        if (lat <= b) {
            const [a, ta] = STRESS[k - 1];
            const s = ta + (tb - ta) * (1 - Math.cos(Math.PI * (lat - a) / (b - a))) / 2;
            return 0.015 * Math.sign(s) * Math.sqrt(Math.abs(s) / 1.56e-3);
        }
    }
    return 0;
}

export const wrap = (lng) => ((lng + 180) % 360 + 360) % 360 - 180;

export function distanceKm(a, b) {
    const s = Math.sin((b.lat - a.lat) * DEG / 2) ** 2
        + Math.cos(a.lat * DEG) * Math.cos(b.lat * DEG) * Math.sin((b.lng - a.lng) * DEG / 2) ** 2;
    return 12742 * Math.asin(Math.min(1, Math.sqrt(s)));
}

const WINDS = ['norte', 'nordeste', 'leste', 'sudeste', 'sul', 'sudoeste', 'oeste', 'noroeste'];
export const heading = (u, v) => (Math.atan2(u, v) / DEG + 360) % 360;
export const compass = (u, v) => WINDS[Math.round(heading(u, v) / 45) % 8];

export function formatCoords(lat, lng) {
    const part = (value, pad, pos, neg) => {
        const total = Math.round(Math.abs(value) * 60);
        return `${String(Math.floor(total / 60)).padStart(pad, '0')}°${String(total % 60).padStart(2, '0')}′ ${value >= 0 ? pos : neg}`;
    };
    return `${part(lat, 2, 'N', 'S')}  ${part(lng, 3, 'L', 'O')}`;
}

export class Ocean {
    static async load() {
        const [coast, field] = await Promise.all(['coast', 'currents'].map(async (name) => {
            const response = await fetch(`/ocean/${name}.bin`);
            if (!response.ok) throw new Error(`Não consegui ler /ocean/${name}.bin`);
            return response.arrayBuffer();
        }));
        return new Ocean(new Uint8Array(coast), new Int16Array(field));
    }

    constructor(coast, field) {
        this.coast = coast;
        this.field = field;
    }

    coastDistance(lat, lng) {
        const x = (wrap(lng) + 180) * 4 - .5, y = Math.max(0, Math.min(COAST_H - 1, (90 - lat) * 4 - .5));
        const x0 = Math.floor(x), y0 = Math.floor(y), fx = x - x0, fy = y - y0;
        const xa = (x0 + COAST_W) % COAST_W, xb = (x0 + 1) % COAST_W, yb = Math.min(COAST_H - 1, y0 + 1);
        const c = this.coast;
        const top = c[y0 * COAST_W + xa] * (1 - fx) + c[y0 * COAST_W + xb] * fx;
        const bottom = c[yb * COAST_W + xa] * (1 - fx) + c[yb * COAST_W + xb] * fx;
        return (top * (1 - fy) + bottom * fy - 128) / 16;
    }

    isSea(lat, lng) {
        return Math.abs(lat) < 87 && this.coastDistance(lat, lng) > 0;
    }

    /** Escreve [u, v] em m/s em `out` para não alocar por partícula. */
    current(lat, lng, out = [0, 0]) {
        const x = wrap(lng) + 179.5, y = Math.max(0, Math.min(FIELD_H - 1, lat + 89.5));
        const x0 = Math.floor(x), y0 = Math.floor(y), fx = x - x0, fy = y - y0;
        const xa = (x0 + FIELD_W) % FIELD_W, xb = (x0 + 1) % FIELD_W, yb = Math.min(FIELD_H - 1, y0 + 1);
        const a = (y0 * FIELD_W + xa) * 2, b = (y0 * FIELD_W + xb) * 2, c = (yb * FIELD_W + xa) * 2, d = (yb * FIELD_W + xb) * 2;
        const f = this.field;
        for (let k = 0; k < 2; k++) {
            out[k] = ((f[a + k] * (1 - fx) + f[b + k] * fx) * (1 - fy) + (f[c + k] * (1 - fx) + f[d + k] * fx) * fy) / 1000;
        }
        return out;
    }

    /** Previsão: um conjunto de derivas possíveis, com a mesma física de App\Ocean\Drifter. */
    forecast(lat, lng, days = 180, members = 24) {
        const gaussian = () => Math.sqrt(-2 * Math.log(1 - Math.random())) * Math.cos(2 * Math.PI * Math.random());
        const dt = .5, scale = dt * SECONDS_PER_DAY / METERS_PER_DEGREE;
        const sigma = Math.sqrt(2 * 2500 / (dt * SECONDS_PER_DAY));
        return Array.from({ length: members }, () => {
            const path = [{ lat, lng }];
            let p = { lat, lng }, ashore = false;
            const move = (from, u, v, s) => ({
                lat: Math.max(-86.5, Math.min(86.5, from.lat + v * s)),
                lng: wrap(from.lng + u * s / Math.max(.05, Math.cos(from.lat * DEG))),
            });
            for (let t = dt; t <= days && !ashore; t += dt) {
                let [u, v] = this.current(p.lat, p.lng);
                const mid = move(p, u, v, scale / 2);
                [u, v] = this.current(mid.lat, mid.lng);
                const next = move(p, u + leeway(mid.lat) + sigma * gaussian(), v + sigma * gaussian(), scale);
                if (this.isSea(next.lat, next.lng)) p = next;
                else if (t >= 5 && Math.random() < .23) ashore = true; // dois passos de 0,25 dia a 12%
                if (t % 3 === 0 || ashore) path.push(p);
            }
            return { path, ashore, end: p };
        });
    }
}

import { wrap, heading } from './ocean.js';

const DEG = Math.PI / 180;
const TRAIL = 18;
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');

const SPHERE_VS = `#version 300 es
in vec2 corner; void main() { gl_Position = vec4(corner, 0., 1.); }`;

// Carta náutica: terra ocre, faixas de profundidade em degraus, linha de costa, gratícula.
const SPHERE_FS = `#version 300 es
precision highp float;
uniform vec2 center; uniform float radius; uniform mat3 view; uniform sampler2D coast; uniform float px;
uniform vec3 ground, deep, mid, shallow, shore, land, ink;
out vec4 color;
const float PI = 3.14159265;
float line(float value, float every, float width) {
    float d = abs(fract(value / every - .5) - .5) * every;
    return 1. - smoothstep(width * .5, width * 1.5, d);
}
void main() {
    vec2 q = (gl_FragCoord.xy - center) / radius;
    float r = length(q);
    float edge = 1.25 * px / radius;
    if (r > 1. + edge) { color = vec4(ground, 1.); return; }
    float z = sqrt(max(0., 1. - r * r));
    vec3 p = transpose(view) * vec3(q, z);
    float lat = asin(clamp(p.z, -1., 1.)), lng = atan(p.y, p.x);
    float d = (textureLod(coast, vec2(lng / (2. * PI) + .5, .5 - lat / PI), 0.).r * 255. - 128.) / 16.;
    float deg = px / radius * 57.2958 / max(z, .12);
    vec3 c = deep;
    c = mix(c, mid, 1. - smoothstep(4.2 - deg, 4.2 + deg, d));
    c = mix(c, shallow, 1. - smoothstep(1.9 - deg, 1.9 + deg, d));
    c = mix(c, shore, 1. - smoothstep(.7 - deg * .6, .7 + deg * .6, d));
    float g = max(line(lat * 57.2958, 15., deg * .55), line(lng * 57.2958, 15., deg * .55 / max(cos(lat), .08)) * step(abs(lat), 1.31));
    c = mix(c, ink, g * .13);
    c = mix(c, land, 1. - smoothstep(-deg * .5, deg * .5, d));
    float w = max(fwidth(d), .004);
    c = mix(c, ink, (1. - smoothstep(w * .5, w * 1.2, abs(d))) * .78 * smoothstep(.06, .3, z) * smoothstep(1.1, .5, w));
    c = mix(c, ink, smoothstep(1. - edge * 1.6, 1. - edge * .4, r));
    color = vec4(mix(c, ground, smoothstep(1., 1. + edge, r)), 1.);
}`;

const TRAIL_VS = `#version 300 es
in vec3 position; in float alpha;
uniform mat3 view; uniform vec2 center, size; uniform float radius; out float a;
void main() {
    vec3 p = view * position;
    a = alpha * smoothstep(.02, .3, p.z);
    gl_Position = vec4((center + p.xy * radius) / size * 2. - 1., 0., 1.);
}`;
const TRAIL_FS = `#version 300 es
precision mediump float; in float a; uniform vec3 tint; out vec4 color;
void main() { if (a < .01) discard; color = vec4(tint, a); }`;

function program(gl, vs, fs) {
    const p = gl.createProgram();
    for (const [type, source] of [[gl.VERTEX_SHADER, vs], [gl.FRAGMENT_SHADER, fs]]) {
        const s = gl.createShader(type);
        gl.shaderSource(s, source);
        gl.compileShader(s);
        if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(s));
        gl.attachShader(p, s);
    }
    gl.linkProgram(p);
    if (!gl.getProgramParameter(p, gl.LINK_STATUS)) throw new Error(gl.getProgramInfoLog(p));
    const uniforms = {};
    for (let k = 0; k < gl.getProgramParameter(p, gl.ACTIVE_UNIFORMS); k++) {
        const { name } = gl.getActiveUniform(p, k);
        uniforms[name] = gl.getUniformLocation(p, name);
    }
    return { p, u: uniforms };
}

const SEA_LABELS = [['Atlântico Norte', 33, -42], ['Atlântico Sul', -25, -16], ['Pacífico Norte', 28, -160], ['Pacífico Sul', -28, -125],
    ['Oceano Índico', -22, 78], ['Oceano Antártico', -62, 30], ['Oceano Antártico', -62, -140], ['Oceano Ártico', 82, 0],
    ['Mar do Caribe', 15, -75, 1.6], ['Golfo do México', 25, -90, 1.6], ['Mediterrâneo', 35, 18, 1.9], ['Mar Arábico', 15, 64, 1.4],
    ['Golfo de Bengala', 14, 88, 1.5], ['Mar da Tasmânia', -38, 160, 1.4], ['Mar de Coral', -17, 153, 1.6], ['Mar do Norte', 56, 3, 2.2],
    ['Mar das Filipinas', 18, 131, 1.5], ['Golfo da Guiné', 1, 3, 1.6], ['Mar do Labrador', 57, -54, 1.5]];

export class Globe {
    constructor(stage, ocean) {
        this.stage = stage;
        this.ocean = ocean;
        [this.canvas, this.overlay] = stage.querySelectorAll('canvas');
        this.ctx = this.overlay.getContext('2d');
        this.view = { lng: -30, lat: -8, zoom: 1 };
        this.velocity = { lng: 0, lat: 0 };
        this.idle = true;
        this.bottles = [];
        this.selected = null;
        this.track = null;
        this.forecast = null;
        this.pin = null;
        this.picking = false;
        this.ripples = [];
        this.handlers = { hover() {}, select() {}, pick() {} };
        this.colors = {};
        this.initGL();
        this.initParticles();
        this.initInput();
        new ResizeObserver(() => this.resize()).observe(stage);
        this.resize();
        this.readTheme();
        requestAnimationFrame((t) => this.frame(t));
    }

    readTheme() {
        const style = getComputedStyle(document.documentElement);
        for (const name of ['ground', 'deep', 'mid', 'shallow', 'shore', 'land', 'ink', 'current', 'mark', 'glass-verde', 'glass-ambar', 'glass-azul', 'glass-claro', 'cork']) {
            const hex = style.getPropertyValue(`--${name}`).trim();
            this.colors[name] = hex;
            this.colors[`${name}3`] = [1, 3, 5].map((k) => parseInt(hex.slice(k, k + 2), 16) / 255);
        }
        this.dirty = true;
    }

    initGL() {
        const gl = this.gl = this.canvas.getContext('webgl2', { antialias: true, alpha: false });
        if (!gl) throw new Error('Seu navegador não oferece WebGL 2, necessário para desenhar o globo.');
        this.sphere = program(gl, SPHERE_VS, SPHERE_FS);
        this.lines = program(gl, TRAIL_VS, TRAIL_FS);
        this.quad = gl.createVertexArray();
        gl.bindVertexArray(this.quad);
        gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer());
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
        const corner = gl.getAttribLocation(this.sphere.p, 'corner');
        gl.enableVertexAttribArray(corner);
        gl.vertexAttribPointer(corner, 2, gl.FLOAT, false, 0, 0);
        gl.bindTexture(gl.TEXTURE_2D, gl.createTexture());
        gl.pixelStorei(gl.UNPACK_ALIGNMENT, 1);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.R8, 1440, 720, 0, gl.RED, gl.UNSIGNED_BYTE, this.ocean.coast);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    }

    initParticles() {
        const gl = this.gl;
        this.count = innerWidth < 700 ? 1500 : 3800;
        this.particles = new Float32Array(this.count * 3); // lat, lng, vida
        this.trails = new Float32Array(this.count * TRAIL * 3);
        this.vertices = new Float32Array(this.count * (TRAIL - 1) * 2 * 4);
        this.head = 0;
        this.trailVao = gl.createVertexArray();
        this.trailBuffer = gl.createBuffer();
        gl.bindVertexArray(this.trailVao);
        gl.bindBuffer(gl.ARRAY_BUFFER, this.trailBuffer);
        gl.bufferData(gl.ARRAY_BUFFER, this.vertices.byteLength, gl.DYNAMIC_DRAW);
        for (const [name, size, offset] of [['position', 3, 0], ['alpha', 1, 12]]) {
            const at = gl.getAttribLocation(this.lines.p, name);
            gl.enableVertexAttribArray(at);
            gl.vertexAttribPointer(at, size, gl.FLOAT, false, 16, offset);
        }
        for (let k = 0; k < this.count; k++) this.spawn(k, true);
        for (let k = 0; k < TRAIL * 4; k++) this.stepParticles();
        this.writeTrails();
    }

    spawn(k, anyAge = false) {
        let lat, lng;
        do {
            lat = Math.asin(Math.random() * 1.94 - .97) / DEG;
            lng = Math.random() * 360 - 180;
        } while (!this.ocean.isSea(lat, lng));
        this.particles[k * 3] = lat;
        this.particles[k * 3 + 1] = lng;
        this.particles[k * 3 + 2] = anyAge ? Math.random() * 160 : 90 + Math.random() * 90;
        const [x, y, z] = unit(lat, lng);
        for (let t = 0; t < TRAIL; t++) this.trails.set([x, y, z], (k * TRAIL + t) * 3);
    }

    stepParticles() {
        const uv = [0, 0], p = this.particles;
        this.head = (this.head + 1) % TRAIL;
        for (let k = 0; k < this.count; k++) {
            const i = k * 3;
            this.ocean.current(p[i], p[i + 1], uv);
            // A raiz comprime o contraste: giros lentos aparecem sem que as bordas virem um borrão.
            const speed = Math.hypot(uv[0], uv[1]);
            const gain = speed > 1e-4 ? .62 * Math.sqrt(speed) / speed : 0;
            const lat = p[i] + uv[1] * gain, lng = wrap(p[i + 1] + uv[0] * gain / Math.max(.15, Math.cos(p[i] * DEG)));
            if (--p[i + 2] < 0 || speed < .004 || !this.ocean.isSea(lat, lng)) {
                this.spawn(k);
                continue;
            }
            p[i] = lat;
            p[i + 1] = lng;
            const o = (k * TRAIL + this.head) * 3, c = Math.cos(lat * DEG);
            this.trails[o] = c * Math.cos(lng * DEG);
            this.trails[o + 1] = c * Math.sin(lng * DEG);
            this.trails[o + 2] = Math.sin(lat * DEG);
        }
    }

    writeTrails() {
        const v = this.vertices, t = this.trails;
        let n = 0;
        for (let k = 0; k < this.count; k++) {
            const life = Math.min(1, this.particles[k * 3 + 2] / 20);
            for (let s = 0; s < TRAIL - 1; s++) {
                for (let e = 0; e < 2; e++) {
                    const o = (k * TRAIL + (this.head + 1 + s + e) % TRAIL) * 3;
                    v[n++] = t[o]; v[n++] = t[o + 1]; v[n++] = t[o + 2];
                    v[n++] = (s + e) / (TRAIL - 1) * .8 * life;
                }
            }
        }
        const gl = this.gl;
        gl.bindBuffer(gl.ARRAY_BUFFER, this.trailBuffer);
        gl.bufferSubData(gl.ARRAY_BUFFER, 0, v);
    }

    resize() {
        const { width, height } = this.stage.getBoundingClientRect();
        this.dpr = Math.min(2, devicePixelRatio || 1);
        this.width = width;
        this.height = height;
        for (const canvas of [this.canvas, this.overlay]) {
            canvas.width = Math.round(width * this.dpr);
            canvas.height = Math.round(height * this.dpr);
        }
        this.dirty = true;
    }

    get radius() {
        const margin = this.width < 700 ? 22 : 46;
        return (Math.min(this.width, this.height) / 2 - margin) * this.view.zoom;
    }

    matrix() {
        const a = this.view.lng * DEG, b = this.view.lat * DEG;
        const ca = Math.cos(a), sa = Math.sin(a), cb = Math.cos(b), sb = Math.sin(b);
        return { ex: [-sa, ca, 0], ey: [-sb * ca, -sb * sa, cb], ez: [cb * ca, cb * sa, sb] };
    }

    /** lat/lng → pixels CSS; z > 0 está de frente para quem olha. */
    project(lat, lng) {
        const p = unit(lat, lng), { ex, ey, ez } = this.m;
        return { x: this.width / 2 + dot(p, ex) * this.radius, y: this.height / 2 - dot(p, ey) * this.radius, z: dot(p, ez) };
    }

    unproject(x, y) {
        const qx = (x - this.width / 2) / this.radius, qy = (this.height / 2 - y) / this.radius, r2 = qx * qx + qy * qy;
        if (r2 > 1) return null;
        const qz = Math.sqrt(1 - r2), { ex, ey, ez } = this.m;
        const p = [0, 1, 2].map((k) => qx * ex[k] + qy * ey[k] + qz * ez[k]);
        return { lat: Math.asin(p[2]) / DEG, lng: Math.atan2(p[1], p[0]) / DEG };
    }

    flyTo(lat, lng, zoom = Math.max(1, this.view.zoom)) {
        this.idle = false;
        const to = { lat: Math.max(-80, Math.min(80, lat)), lng: this.view.lng + wrap(lng - this.view.lng), zoom };
        if (reducedMotion.matches) {
            this.view = { ...to, lng: wrap(to.lng) };
            this.dirty = true;
            return;
        }
        this.flight = { from: { ...this.view }, to, start: performance.now(), time: 1100 };
    }

    nearestBottle(x, y, reach = 16) {
        let best = null, distance = reach;
        for (const b of this.bottles) {
            const p = this.project(b.lat, b.lng);
            const d = Math.hypot(p.x - x, p.y - y);
            if (p.z > .05 && d < distance) [best, distance] = [b, d];
        }
        return best;
    }

    initInput() {
        const el = this.overlay, pointers = new Map();
        let moved = 0, pinch = 0, last = null;
        const local = (e) => {
            const r = el.getBoundingClientRect();
            return { x: e.clientX - r.left, y: e.clientY - r.top };
        };
        el.addEventListener('pointerdown', (e) => {
            el.setPointerCapture(e.pointerId);
            pointers.set(e.pointerId, local(e));
            last = local(e);
            moved = 0;
            this.flight = null;
            this.velocity = { lng: 0, lat: 0 };
        });
        el.addEventListener('pointermove', (e) => {
            const at = local(e);
            if (!pointers.has(e.pointerId)) {
                const near = this.picking ? null : this.nearestBottle(at.x, at.y);
                el.style.cursor = this.picking ? 'crosshair' : near ? 'pointer' : 'grab';
                this.hovered = near?.id ?? null;
                this.handlers.hover(this.unproject(at.x, at.y), near);
                return;
            }
            const before = pointers.get(e.pointerId);
            pointers.set(e.pointerId, at);
            if (pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                const d = Math.hypot(a.x - b.x, a.y - b.y);
                if (pinch) this.zoomBy(d / pinch);
                pinch = d;
                moved = 99;
                return;
            }
            moved += Math.hypot(at.x - before.x, at.y - before.y);
            if (moved < 5) return;
            this.idle = false;
            el.style.cursor = 'grabbing';
            const k = 1 / (this.radius * DEG);
            this.velocity = { lng: -(at.x - before.x) * k, lat: (at.y - before.y) * k };
            this.turn(this.velocity.lng, this.velocity.lat);
        });
        const end = (e) => {
            if (!pointers.delete(e.pointerId)) return;
            pinch = 0;
            if (e.type === 'pointercancel' || moved >= 5 || pointers.size) return;
            const at = local(e), point = this.unproject(at.x, at.y);
            this.idle = false;
            if (this.picking) {
                if (point) this.handlers.pick(point);
                return;
            }
            const near = this.nearestBottle(at.x, at.y, e.pointerType === 'touch' ? 26 : 16);
            if (near) this.handlers.select(near.id);
            else if (point) this.handlers.hover(point, null);
        };
        el.addEventListener('pointerup', end);
        el.addEventListener('pointercancel', end);
        el.addEventListener('wheel', (e) => {
            e.preventDefault();
            this.idle = false;
            this.zoomBy(Math.exp(-e.deltaY * .0014));
        }, { passive: false });
        el.addEventListener('keydown', (e) => {
            const step = 6 / this.view.zoom;
            const turns = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
            if (turns[e.key]) this.turn(...turns[e.key]);
            else if (e.key === '+' || e.key === '=') this.zoomBy(1.2);
            else if (e.key === '-') this.zoomBy(1 / 1.2);
            else if (e.key === 'Enter' && this.picking) this.handlers.pick({ lat: this.view.lat, lng: this.view.lng });
            else return;
            e.preventDefault();
            this.idle = false;
            this.handlers.hover({ lat: this.view.lat, lng: this.view.lng }, null);
        });
    }

    turn(lng, lat) {
        this.view.lng = wrap(this.view.lng + lng);
        this.view.lat = Math.max(-82, Math.min(82, this.view.lat + lat));
        this.dirty = true;
    }

    zoomBy(factor) {
        this.view.zoom = Math.max(.8, Math.min(6, this.view.zoom * factor));
        this.dirty = true;
    }

    ripple(lat, lng) {
        this.ripples.push({ lat, lng, start: performance.now() });
    }

    frame(now) {
        requestAnimationFrame((t) => this.frame(t));
        if (document.hidden) return;
        const dt = Math.min(50, now - (this.last ?? now));
        this.last = now;
        const still = reducedMotion.matches;

        if (this.flight) {
            const { from, to, start, time } = this.flight;
            const t = Math.min(1, (now - start) / time), k = t < .5 ? 4 * t ** 3 : 1 - (-2 * t + 2) ** 3 / 2;
            for (const key of ['lng', 'lat', 'zoom']) this.view[key] = from[key] + (to[key] - from[key]) * k;
            if (t === 1) {
                this.flight = null;
                this.view.lng = wrap(this.view.lng);
            }
        } else if (!still) {
            if (this.idle) this.turn(dt * .0022, 0);
            else if (Math.abs(this.velocity.lng) + Math.abs(this.velocity.lat) > .004) {
                this.velocity.lng *= .93;
                this.velocity.lat *= .93;
                this.turn(this.velocity.lng, this.velocity.lat);
            }
        }
        if (!still) {
            this.clock = (this.clock ?? 0) + dt;
            if (this.clock > 30) {
                this.clock = 0;
                this.stepParticles();
                this.writeTrails();
            }
        }
        if (still && !this.dirty && !this.flight && !this.ripples.length) return;
        this.dirty = false;
        this.m = this.matrix();
        this.drawGL();
        this.drawOverlay(now);
    }

    drawGL() {
        const { gl, colors: c, dpr } = this, { ex, ey, ez } = this.m;
        const w = this.canvas.width, h = this.canvas.height;
        // Colunas de M: M·p = (ex·p, ey·p, ez·p)
        const view = [ex[0], ey[0], ez[0], ex[1], ey[1], ez[1], ex[2], ey[2], ez[2]];
        gl.viewport(0, 0, w, h);
        gl.disable(gl.BLEND);
        gl.useProgram(this.sphere.p);
        gl.uniform2f(this.sphere.u.center, w / 2, h / 2);
        gl.uniform1f(this.sphere.u.radius, this.radius * dpr);
        gl.uniform1f(this.sphere.u.px, dpr);
        gl.uniformMatrix3fv(this.sphere.u.view, false, view);
        for (const name of ['ground', 'deep', 'mid', 'shallow', 'shore', 'land', 'ink']) gl.uniform3fv(this.sphere.u[name], c[`${name}3`]);
        gl.bindVertexArray(this.quad);
        gl.drawArrays(gl.TRIANGLES, 0, 3);

        gl.enable(gl.BLEND);
        gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
        gl.useProgram(this.lines.p);
        gl.uniform2f(this.lines.u.center, w / 2, h / 2);
        gl.uniform2f(this.lines.u.size, w, h);
        gl.uniform1f(this.lines.u.radius, this.radius * dpr);
        gl.uniformMatrix3fv(this.lines.u.view, false, view);
        gl.uniform3fv(this.lines.u.tint, c.current3);
        gl.bindVertexArray(this.trailVao);
        gl.drawArrays(gl.LINES, 0, this.vertices.length / 4);
    }

    drawOverlay(now) {
        const { ctx } = this;
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        ctx.clearRect(0, 0, this.width, this.height);
        this.drawLabels();
        this.drawRose();
        if (this.forecast) this.drawForecast();
        if (this.track) this.drawPath(this.track, 2, [6, 4], this.colors.mark);
        if (this.track?.length) this.drawOrigin(this.track[0]);

        for (const b of this.bottles) if (b.id !== this.selected && b.id !== this.hovered) this.drawBottle(b);
        for (const id of [this.hovered, this.selected]) {
            const b = id && this.bottles.find((x) => x.id === id);
            if (b) this.drawBottle(b, 1.45);
        }
        if (this.pin) this.drawPin(this.pin);
        this.ripples = this.ripples.filter((r) => now - r.start < 1800);
        for (const r of this.ripples) {
            const p = this.project(r.lat, r.lng), t = (now - r.start) / 1800;
            if (p.z < 0) continue;
            ctx.globalAlpha = 1 - t;
            ctx.strokeStyle = this.colors.mark;
            ctx.lineWidth = 2;
            for (const k of [1, .55]) {
                ctx.beginPath();
                ctx.arc(p.x, p.y, 6 + 46 * t * k, 0, 7);
                ctx.stroke();
            }
            ctx.globalAlpha = 1;
        }
    }

    drawLabels() {
        const { ctx } = this;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = this.colors.ink;
        for (const [name, lat, lng, minZoom = 0] of SEA_LABELS) {
            if (this.view.zoom < minZoom) continue;
            const p = this.project(lat, lng);
            if (p.z < .35) continue;
            ctx.globalAlpha = Math.min(.62, (p.z - .35) * 2.4);
            ctx.font = `italic ${minZoom ? 13 : 15}px "Fira Sans", sans-serif`; // água se escreve em itálico
            ctx.fillText(name, p.x, p.y);
        }
        ctx.globalAlpha = 1;
    }

    /** Rosa graduada em magenta, como nas cartas; a agulha mostra o rumo da garrafa escolhida. */
    drawRose() {
        const { ctx, radius: r } = this, cx = this.width / 2, cy = this.height / 2;
        if (this.view.zoom > 1.35) return;
        const room = this.width >= 700;
        ctx.strokeStyle = ctx.fillStyle = this.colors.mark;
        ctx.lineWidth = 1;
        ctx.beginPath();
        for (let a = 0; a < 360; a += 2) {
            const long = a % 30 === 0 ? 12 : a % 10 === 0 ? 8 : 4;
            const s = Math.sin(a * DEG), c = -Math.cos(a * DEG);
            ctx.moveTo(cx + s * (r + 6), cy + c * (r + 6));
            ctx.lineTo(cx + s * (r + 6 + long), cy + c * (r + 6 + long));
        }
        ctx.stroke();
        if (room) {
            ctx.font = '500 12px "Fira Sans", sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            for (let a = 0; a < 360; a += 30) {
                const s = Math.sin(a * DEG), c = -Math.cos(a * DEG);
                ctx.fillText(String(a).padStart(3, '0'), cx + s * (r + 30), cy + c * (r + 30));
            }
        }
        const b = this.selected && this.bottles.find((x) => x.id === this.selected);
        if (b && b.status === 'drifting') {
            const a = heading(b.u, b.v) * DEG, s = Math.sin(a), c = -Math.cos(a);
            ctx.beginPath();
            ctx.moveTo(cx + s * (r + 2), cy + c * (r + 2));
            ctx.lineTo(cx + s * (r + 20) + c * 7, cy + c * (r + 20) - s * 7);
            ctx.lineTo(cx + s * (r + 20) - c * 7, cy + c * (r + 20) + s * 7);
            ctx.fill();
        }
    }

    drawPath(points, width, dash, color, alpha = 1) {
        const { ctx } = this;
        ctx.strokeStyle = color;
        ctx.globalAlpha = alpha;
        ctx.lineWidth = width;
        ctx.lineJoin = 'round';
        ctx.setLineDash(dash);
        ctx.beginPath();
        let pen = false;
        for (const q of points) {
            const p = this.project(q.lat, q.lng);
            if (p.z < 0) { pen = false; continue; }
            if (pen) ctx.lineTo(p.x, p.y); else ctx.moveTo(p.x, p.y);
            pen = true;
        }
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.globalAlpha = 1;
    }

    drawOrigin(q) {
        const p = this.project(q.lat, q.lng), { ctx } = this;
        if (p.z < 0) return;
        ctx.strokeStyle = this.colors.mark;
        ctx.fillStyle = this.colors.deep;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(p.x, p.y, 5, 0, 7);
        ctx.fill();
        ctx.stroke();
    }

    drawForecast() {
        for (const { path } of this.forecast) this.drawPath(path, 1.25, [], this.colors.mark, .42);
        const { ctx } = this;
        for (const { end, ashore } of this.forecast) {
            const p = this.project(end.lat, end.lng);
            if (p.z < 0) continue;
            ctx.fillStyle = this.colors.mark;
            ctx.beginPath();
            ctx.arc(p.x, p.y, ashore ? 4.5 : 2.5, 0, 7);
            ctx.fill();
        }
    }

    drawPin(q) {
        const p = this.project(q.lat, q.lng), { ctx } = this;
        if (p.z < 0) return;
        ctx.strokeStyle = this.colors.mark;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(p.x, p.y, 9, 0, 7);
        for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
            ctx.moveTo(p.x + dx * 5, p.y + dy * 5);
            ctx.lineTo(p.x + dx * 16, p.y + dy * 16);
        }
        ctx.stroke();
    }

    drawBottle(b, scale = 1) {
        const p = this.project(b.lat, b.lng), { ctx } = this;
        if (p.z < .04) return;
        const ashore = b.status === 'ashore';
        ctx.save();
        ctx.globalAlpha = Math.min(1, p.z * 4) * (b.status === 'opened' ? .55 : 1);
        ctx.translate(p.x, p.y);
        ctx.scale(scale, scale);
        if (ashore) { // a lágrima magenta que marca os faróis nas cartas
            ctx.fillStyle = this.colors.mark;
            ctx.beginPath();
            ctx.moveTo(0, 0);
            ctx.bezierCurveTo(9, -5, 19, -11, 17, -17);
            ctx.bezierCurveTo(14, -21, 6, -11, 0, 0);
            ctx.fill();
        }
        ctx.rotate(ashore || b.status === 'opened' ? -.5 : heading(b.u, b.v) * DEG);
        bottlePath(ctx);
        ctx.fillStyle = this.colors[`glass-${b.glass}`];
        ctx.fill();
        ctx.lineWidth = b.mine || b.forMe ? 2 : 1.1;
        ctx.strokeStyle = b.mine || b.forMe ? this.colors.mark : this.colors.ink;
        ctx.stroke();
        ctx.fillStyle = this.colors.cork;
        ctx.fillRect(-1.6, -10.4, 3.2, 2.8);
        ctx.restore();
    }
}

function bottlePath(ctx) {
    ctx.beginPath();
    ctx.moveTo(-1.6, -7.6);
    ctx.lineTo(-1.6, -4.4);
    ctx.bezierCurveTo(-4, -3, -4, -2, -4, 0);
    ctx.lineTo(-4, 6);
    ctx.quadraticCurveTo(-4, 7.6, -2.4, 7.6);
    ctx.lineTo(2.4, 7.6);
    ctx.quadraticCurveTo(4, 7.6, 4, 6);
    ctx.lineTo(4, 0);
    ctx.bezierCurveTo(4, -2, 4, -3, 1.6, -4.4);
    ctx.lineTo(1.6, -7.6);
    ctx.closePath();
}

const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
const unit = (lat, lng) => {
    const c = Math.cos(lat * DEG);
    return [c * Math.cos(lng * DEG), c * Math.sin(lng * DEG), Math.sin(lat * DEG)];
};

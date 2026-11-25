/** Som do mar sintetizado: ruído filtrado que respira em dois ciclos de onda. Nada é baixado. */
let context;

export async function toggleSea(on) {
    if (!on) return context?.suspend();
    if (!context) {
        context = new AudioContext();
        const buffer = context.createBuffer(1, context.sampleRate * 6, context.sampleRate);
        const data = buffer.getChannelData(0);
        for (let k = 0, brown = 0; k < data.length; k++) {
            brown = (brown + .02 * (Math.random() * 2 - 1)) / 1.02;
            data[k] = brown * 3.2;
        }
        const master = context.createGain();
        master.gain.value = .5;
        master.connect(context.destination);
        for (const [cutoff, period, depth] of [[420, 9.5, .22], [1400, 6.7, .1]]) {
            const source = context.createBufferSource();
            source.buffer = buffer;
            source.loop = true;
            source.playbackRate.value = cutoff > 1000 ? 1.6 : 1;
            const filter = context.createBiquadFilter();
            filter.type = 'lowpass';
            filter.frequency.value = cutoff;
            const gain = context.createGain();
            gain.gain.value = depth * 1.2;
            const wave = context.createOscillator(), swell = context.createGain();
            wave.frequency.value = 1 / period;
            swell.gain.value = depth;
            wave.connect(swell).connect(gain.gain);
            source.connect(filter).connect(gain).connect(master);
            source.start();
            wave.start();
        }
    }
    return context.resume();
}

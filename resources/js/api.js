const token = document.querySelector('meta[name=csrf-token]').content;

async function call(method, url, body) {
    const response = await fetch(url, {
        method,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: body && JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(data.message ?? 'O mar não respondeu. Tente de novo.');
        error.fields = data.errors ?? {};
        error.status = response.status;
        throw error;
    }
    return data;
}

export const api = {
    sea: () => call('GET', '/mar'),
    here: ({ lat, lng }) => call('GET', `/mar/aqui?lat=${lat.toFixed(4)}&lng=${lng.toFixed(4)}`),
    logbook: () => call('GET', '/mar/diario'),
    bottle: (id) => call('GET', `/garrafas/${id}`),
    launch: (data) => call('POST', '/garrafas', data),
    open: (id) => call('POST', `/garrafas/${id}/abrir`),
    reply: (id, data) => call('POST', `/garrafas/${id}/resposta`, data),
};

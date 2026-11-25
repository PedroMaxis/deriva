<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Escreva uma carta, lance ao mar e deixe as correntes decidirem quem vai ler.">
    <title>Deriva, o correio do mar</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite('resources/js/app.js')
</head>
<body>
    <main class="bridge">
        <section class="side" aria-label="Garrafas">
            <header class="masthead">
                <div class="brand">
                    <svg viewBox="0 0 40 40" width="38" height="38" aria-hidden="true"><rect x="-1.6" y="-10.4" width="3.2" height="2.6" transform="translate(21 17) rotate(22) scale(1.65)" fill="currentColor"/><path d="M-1.6-7.2V-4.400C-4-3-4-2-4 0V6Q-4 7.6-2.4 7.6H2.4Q4 7.6 4 6V0C4-2 4-3 1.6-4.400V-7.200Z" transform="translate(21 17) rotate(22) scale(1.65)" fill="var(--mark)"/><path d="M2 32c4.500-4 7.500-4 12 0s7.500 4 12 0 7.500-4 12 0" fill="none" stroke="currentColor" stroke-width="4"/></svg>
                    <h1>Deriva</h1>
                </div>
                <div class="tools">
                    <button type="button" class="link" id="sound" aria-pressed="false">Ouvir o mar</button>
                    <button type="button" class="link" id="theme">Modo noite</button>
                </div>
                <p class="finding" id="finding">Lendo o mar…</p>
                <button type="button" class="primary" id="launch">Lançar uma garrafa</button>
            </header>
            <div class="panel" id="panel"><p class="muted">Abrindo a carta náutica…</p></div>
        </section>

        <section class="chart" aria-label="Carta do mundo">
            <div class="stage" id="stage">
                <canvas aria-hidden="true"></canvas>
                <canvas tabindex="0" role="application" aria-label="Globo com as garrafas. Setas giram, mais e menos aproximam, Enter escolhe o centro ao lançar."></canvas>
            </div>
            <p class="news" id="news" role="status"></p>
            <footer class="strip">
                <p class="readout" id="readout"></p>
                <p class="scale">1 minuto aqui é {{ rtrim(rtrim(number_format(config('ocean.days_per_minute'), 2, ',', ''), '0'), ',') }} dia no mar. Geografia: Natural Earth</p>
            </footer>
        </section>
    </main>

    <dialog class="letter" id="letter" aria-label="Carta">
        <div class="letter-body" id="letter-body"></div>
        <div class="letter-actions">
            <button type="button" class="primary" id="letter-reply">Responder</button>
            <button type="button" class="secondary" id="letter-close">Guardar a carta</button>
        </div>
    </dialog>
    <noscript>O mar daqui se move com JavaScript. Ative-o para ver as garrafas.</noscript>
</body>
</html>

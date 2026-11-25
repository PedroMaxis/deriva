<?php

return [
    // Quantos dias de mar passam em cada minuto real.
    'days_per_minute' => (float) env('OCEAN_DAYS_PER_MINUTE', 1),

    // Dias mínimos de mar antes de uma garrafa poder encalhar.
    'min_days_at_sea' => 5,

    // Chance de ficar na praia a cada toque na costa.
    'landfall_chance' => 0.12,

    // Uma garrafa nunca achada se desfaz (índice TTL) depois disso, em dias de mar.
    'dissolve_after_days' => 1825,

    // Turbulência: difusividade horizontal em m²/s.
    'diffusivity' => 2500,

    // Trajetórias antigas saem da coleção time series depois disto, em dias reais.
    'drift_log_days' => 30,

    'launches_per_hour' => (int) env('OCEAN_LAUNCHES_PER_HOUR', 12),

    'data_path' => public_path('ocean'),
];

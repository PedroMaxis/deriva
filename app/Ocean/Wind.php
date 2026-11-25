<?php

namespace App\Ocean;

/** Vento zonal médio: alísios, ventos de oeste e polares. O mesmo que move o oceano empurra o gargalo da garrafa. */
final class Wind
{
    /** Tensão zonal (N/m²) por latitude. */
    private const STRESS = [
        [-90, 0.0], [-75, -0.02], [-65, -0.01], [-50, 0.15], [-38, 0.07], [-28, -0.01], [-15, -0.08], [0, -0.025],
        [8, -0.04], [16, -0.08], [28, -0.005], [38, 0.06], [48, 0.09], [60, 0.02], [70, -0.02], [90, 0.0],
    ];

    private const AIR_DRAG = 1.2 * 1.3e-3;

    /** Fração da velocidade do vento que a garrafa pega. */
    private const LEEWAY = 0.015;

    public static function stress(float $lat): float
    {
        for ($k = 1; $k < count(self::STRESS); $k++) {
            [$b, $tb] = self::STRESS[$k];
            if ($lat <= $b) {
                [$a, $ta] = self::STRESS[$k - 1];
                $t = (1 - cos(M_PI * ($lat - $a) / ($b - $a))) / 2;

                return $ta + ($tb - $ta) * $t;
            }
        }

        return 0.0;
    }

    /** Arrasto do vento sobre a garrafa, em m/s para leste. */
    public static function leeway(float $lat): float
    {
        $stress = self::stress($lat);

        return self::LEEWAY * ($stress <=> 0.0) * sqrt(abs($stress) / self::AIR_DRAG);
    }
}

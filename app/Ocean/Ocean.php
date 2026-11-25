<?php

namespace App\Ocean;

use RuntimeException;

/** Leitura do oceano pré-calculado por `ocean:build`. */
final class Ocean
{
    private string $coast;

    /** @var array<int, int> */
    private array $field;

    public function __construct(string $path)
    {
        if (! is_file("$path/coast.bin") || ! is_file("$path/currents.bin")) {
            throw new RuntimeException('O oceano ainda não foi construído. Rode: php artisan ocean:build');
        }

        $this->coast = file_get_contents("$path/coast.bin");
        $this->field = array_values(unpack('s*', file_get_contents("$path/currents.bin")));
    }

    /** Distância até a costa em graus: positiva no mar, negativa em terra. */
    public function coastDistance(float $lat, float $lng): float
    {
        $w = OceanBuilder::COAST_W;
        $h = OceanBuilder::COAST_H;
        $x = (Geo::wrap($lng) + 180.0) * 4 - 0.5;
        $y = max(0.0, min($h - 1.0, (90.0 - $lat) * 4 - 0.5));
        $x0 = (int) floor($x);
        $y0 = (int) floor($y);
        $fx = $x - $x0;
        $fy = $y - $y0;
        $x1 = ($x0 + 1) % $w;
        $x0 = ($x0 + $w) % $w;
        $y1 = min($h - 1, $y0 + 1);
        $top = ord($this->coast[$y0 * $w + $x0]) * (1 - $fx) + ord($this->coast[$y0 * $w + $x1]) * $fx;
        $bottom = ord($this->coast[$y1 * $w + $x0]) * (1 - $fx) + ord($this->coast[$y1 * $w + $x1]) * $fx;

        return ($top * (1 - $fy) + $bottom * $fy - 128) / 16;
    }

    public function isSea(float $lat, float $lng): bool
    {
        return abs($lat) < 87 && $this->coastDistance($lat, $lng) > 0;
    }

    /** @return array{0: float, 1: float} corrente (u leste, v norte) em m/s */
    public function current(float $lat, float $lng): array
    {
        $w = OceanBuilder::FIELD_W;
        $h = OceanBuilder::FIELD_H;
        $x = Geo::wrap($lng) + 179.5;
        $y = max(0.0, min($h - 1.0, $lat + 89.5));
        $x0 = (int) floor($x);
        $y0 = (int) floor($y);
        $fx = $x - $x0;
        $fy = $y - $y0;
        $x1 = ($x0 + 1) % $w;
        $x0 = ($x0 + $w) % $w;
        $y1 = min($h - 1, $y0 + 1);
        $out = [];
        foreach ([0, 1] as $c) {
            $south = $this->field[($y0 * $w + $x0) * 2 + $c] * (1 - $fx) + $this->field[($y0 * $w + $x1) * 2 + $c] * $fx;
            $north = $this->field[($y1 * $w + $x0) * 2 + $c] * (1 - $fx) + $this->field[($y1 * $w + $x1) * 2 + $c] * $fx;
            $out[] = ($south * (1 - $fy) + $north * $fy) / 1000;
        }

        return $out;
    }

    /** @return array{0: float, 1: float} ponto de mar aberto mais próximo, em espiral */
    public function nearestSea(float $lat, float $lng, float $clearance = 0.4): array
    {
        for ($radius = 0.0; $radius < 12; $radius += 0.25) {
            $turns = max(1, (int) ($radius * 32));
            for ($k = 0; $k < $turns; $k++) {
                $angle = 2 * M_PI * $k / $turns;
                $la = max(-85.0, min(85.0, $lat + $radius * sin($angle)));
                $lo = Geo::wrap($lng + $radius * cos($angle) / max(0.2, cos(deg2rad($lat))));
                if ($this->coastDistance($la, $lo) > $clearance) {
                    return [$la, $lo];
                }
            }
        }

        return [$lat, $lng];
    }
}

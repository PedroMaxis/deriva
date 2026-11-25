<?php

namespace App\Ocean;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/** Leva uma garrafa pelo mar: advecção RK2 na corrente, arrasto do vento e difusão turbulenta. */
final class Drifter
{
    private const STEP_DAYS = 0.25;

    private const SECONDS_PER_DAY = 86400;

    private const METERS_PER_DEGREE = 111_195;

    private Randomizer $random;

    public function __construct(private readonly Ocean $ocean, ?int $seed = null)
    {
        $this->random = new Randomizer($seed === null ? null : new Xoshiro256StarStar($seed));
    }

    /**
     * @param  float  $age  dias que a garrafa já passou no mar
     * @param  float  $sampleEvery  dias entre amostras da trajetória
     */
    public function advance(float $lat, float $lng, float $days, float $age = 0.0, float $sampleEvery = 1.0): Voyage
    {
        $voyage = new Voyage($lat, $lng);
        $nextSample = $sampleEvery;

        while ($voyage->days < $days - 1.0e-9) {
            $dt = min(self::STEP_DAYS, $days - $voyage->days);
            [$u, $v] = $this->ocean->current($voyage->lat, $voyage->lng);
            [$midLat, $midLng] = $this->move($voyage->lat, $voyage->lng, $u, $v, $dt / 2);
            [$u, $v] = $this->ocean->current($midLat, $midLng);
            $u += Wind::leeway($midLat);

            $sigma = sqrt(2 * config('ocean.diffusivity') / ($dt * self::SECONDS_PER_DAY));
            [$nextLat, $nextLng] = $this->move(
                $voyage->lat, $voyage->lng, $u + $sigma * $this->gaussian(), $v + $sigma * $this->gaussian(), $dt,
            );

            $voyage->days += $dt;

            if (! $this->ocean->isSea($nextLat, $nextLng)) {
                if ($age + $voyage->days >= config('ocean.min_days_at_sea') && $this->random->nextFloat() < config('ocean.landfall_chance')) {
                    $voyage->ashore = true;
                    $voyage->u = $voyage->v = 0.0;
                    $voyage->samples[] = $this->sample($voyage);

                    return $voyage;
                }

                continue; // a onda a devolve ao mar
            }

            $voyage->km += hypot($u, $v) * $dt * self::SECONDS_PER_DAY / 1000;
            [$voyage->lat, $voyage->lng, $voyage->u, $voyage->v] = [$nextLat, $nextLng, $u, $v];

            if ($voyage->days >= $nextSample - 1.0e-9) {
                $voyage->samples[] = $this->sample($voyage);
                $nextSample += $sampleEvery;
            }
        }

        return $voyage;
    }

    private function sample(Voyage $voyage): array
    {
        return ['day' => $voyage->days, 'lat' => $voyage->lat, 'lng' => $voyage->lng, 'speed' => hypot($voyage->u, $voyage->v)];
    }

    /** @return array{0: float, 1: float} */
    private function move(float $lat, float $lng, float $u, float $v, float $days): array
    {
        $scale = $days * self::SECONDS_PER_DAY / self::METERS_PER_DEGREE;

        return [
            max(-86.5, min(86.5, $lat + $v * $scale)),
            Geo::wrap($lng + $u * $scale / max(0.05, cos(deg2rad($lat)))),
        ];
    }

    private function gaussian(): float
    {
        return sqrt(-2 * log(1 - $this->random->nextFloat())) * cos(2 * M_PI * $this->random->nextFloat());
    }
}

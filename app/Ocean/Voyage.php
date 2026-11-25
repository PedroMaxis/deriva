<?php

namespace App\Ocean;

/** Resultado de um trecho de deriva. */
final class Voyage
{
    /** @var array<int, array{day: float, lat: float, lng: float, speed: float}> */
    public array $samples = [];

    public bool $ashore = false;

    public function __construct(
        public float $lat,
        public float $lng,
        public float $days = 0.0,
        public float $km = 0.0,
        public float $u = 0.0,
        public float $v = 0.0,
    ) {}
}

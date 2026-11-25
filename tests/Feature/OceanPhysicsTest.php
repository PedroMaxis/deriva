<?php

namespace Tests\Feature;

use App\Ocean\Drifter;
use App\Ocean\Geo;
use App\Ocean\Ocean;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OceanPhysicsTest extends TestCase
{
    public function test_it_tells_land_from_sea(): void
    {
        $ocean = app(Ocean::class);

        $this->assertFalse($ocean->isSea(-15.8, -47.9), 'Brasília');
        $this->assertFalse($ocean->isSea(48.8, 2.3), 'Paris');
        $this->assertTrue($ocean->isSea(0, -25), 'Atlântico equatorial');
        $this->assertTrue($ocean->isSea(0, 180), 'linha de data');
    }

    /** As grandes correntes não foram desenhadas: têm de emergir da equação. */
    #[DataProvider('knownCurrents')]
    public function test_known_currents_emerge(float $lat, float $lng, int $east, int $north): void
    {
        [$u, $v] = app(Ocean::class)->current($lat, $lng);

        if ($east !== 0) {
            $this->assertSame($east, $u <=> 0.0, "u=$u");
        }
        if ($north !== 0) {
            $this->assertSame($north, $v <=> 0.0, "v=$v");
        }
    }

    public static function knownCurrents(): array
    {
        return [
            'Golfo' => [31, -79, 1, 1],
            'Kuroshio' => [33, 138, 1, 1],
            'Brasil' => [-28, -46, -1, -1],
            'Agulhas' => [-33, 30, -1, -1],
            'Humboldt' => [-20, -73, 0, 1],
            'Benguela' => [-25, 12, 0, 1],
            'Califórnia' => [33, -122, 0, -1],
            'Circumpolar Antártica' => [-52, 100, 1, 0],
            'Sul Equatorial' => [-15, -20, -1, 0],
        ];
    }

    public function test_a_bottle_never_leaves_the_sea(): void
    {
        $ocean = app(Ocean::class);

        foreach (range(1, 6) as $seed) {
            $voyage = (new Drifter($ocean, $seed))->advance(-23.5, -42, 200);
            $this->assertTrue($ocean->isSea($voyage->lat, $voyage->lng));
        }
    }

    public function test_the_same_seed_gives_the_same_voyage(): void
    {
        $ocean = app(Ocean::class);
        $a = (new Drifter($ocean, 7))->advance(10, -30, 60);
        $b = (new Drifter($ocean, 7))->advance(10, -30, 60);

        $this->assertSame([$a->lat, $a->lng], [$b->lat, $b->lng]);
        $this->assertCount(60, $a->samples);
    }

    public function test_a_bottle_cannot_land_before_its_minimum_time_at_sea(): void
    {
        config(['ocean.landfall_chance' => 1.0]);
        $voyage = (new Drifter(app(Ocean::class), 3))->advance(-22.99, -43.15, 4.9);

        $this->assertFalse($voyage->ashore);
    }

    public function test_it_crosses_the_date_line(): void
    {
        $voyage = (new Drifter(app(Ocean::class), 1))->advance(-53, 179.5, 10);

        $this->assertLessThan(0, $voyage->lng);
        $this->assertGreaterThan(-180, $voyage->lng);
    }

    public function test_compass_uses_portuguese_winds(): void
    {
        $this->assertSame('L', Geo::compass(1, 0));
        $this->assertSame('SO', Geo::compass(-1, -1));
    }

    public function test_replies_are_launched_from_open_water(): void
    {
        $ocean = app(Ocean::class);
        [$lat, $lng] = $ocean->nearestSea(-22.9, -43.2);

        $this->assertGreaterThan(0.4, $ocean->coastDistance($lat, $lng));
        $this->assertLessThan(300, Geo::distanceKm(-22.9, -43.2, $lat, $lng));
    }
}

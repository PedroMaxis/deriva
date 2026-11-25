<?php

namespace Tests\Feature;

use App\Http\Middleware\IdentifySailor;
use App\Models\Bottle;
use App\Ocean\Atlas;
use App\Ocean\Drifter;
use App\Ocean\Geo;
use App\Ocean\Ocean;
use App\Ocean\Post;
use App\Ocean\Tide;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use Tests\OceanTestCase;

class BottlePostTest extends OceanTestCase
{
    private string $alice;

    private string $bento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = (string) Str::uuid();
        $this->bento = (string) Str::uuid();
    }

    private function as(string $sailor): self
    {
        return $this->withCredentials()->withCookie(IdentifySailor::COOKIE, $sailor);
    }

    private function launch(string $sailor, array $extra = []): string
    {
        return $this->as($sailor)->postJson('/garrafas', [
            'letter' => 'Segredo de alto-mar', 'signature' => 'Alice', 'glass' => 'verde', 'lat' => -24, 'lng' => -40, ...$extra,
        ])->assertCreated()->json('id');
    }

    private function strand(string $id): void
    {
        Bottle::find($id)->update(['status' => Bottle::ASHORE, 'shore' => 'Namíbia', 'ashore_at' => now(),
            'position' => Geo::point(-22.9, 14.3)]);
    }

    public function test_launching_puts_a_bottle_at_sea(): void
    {
        $id = $this->launch($this->alice);
        $bottle = Bottle::find($id);

        $this->assertSame(Bottle::DRIFTING, $bottle->status);
        $this->assertSame('Atlântico Sul', $bottle->launched_sea);
        $this->assertNotNull($bottle->dissolves_at);
    }

    public function test_land_and_empty_letters_are_refused(): void
    {
        $this->as($this->alice)->postJson('/garrafas', ['letter' => 'oi mar', 'glass' => 'verde', 'lat' => -15.8, 'lng' => -47.9])
            ->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->as($this->alice)->postJson('/garrafas', ['letter' => '', 'glass' => 'verde', 'lat' => -24, 'lng' => -40])
            ->assertUnprocessable()->assertJsonValidationErrors('letter');
    }

    public function test_the_chart_never_leaks_a_letter(): void
    {
        $id = $this->launch($this->alice);

        foreach (['/mar', "/garrafas/$id", '/mar/diario', '/mar/aqui?lat=-24&lng=-40'] as $url) {
            $body = $this->as($this->bento)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Segredo', $body, $url);
            $this->assertStringNotContainsString(IdentifySailor::key($this->alice), $body, $url);
        }
        $this->as($this->alice)->getJson("/garrafas/$id")->assertJsonPath('letter', 'Segredo de alto-mar');
    }

    public function test_a_bottle_at_sea_cannot_be_opened(): void
    {
        $id = $this->launch($this->alice);
        $this->as($this->bento)->postJson("/garrafas/$id/abrir")->assertConflict();
    }

    public function test_exactly_one_person_opens_a_stranded_bottle(): void
    {
        $id = $this->launch($this->alice);
        $this->strand($id);

        $this->as($this->alice)->postJson("/garrafas/$id/abrir")->assertConflict();
        $this->as($this->bento)->postJson("/garrafas/$id/abrir")->assertOk()->assertJsonPath('letter', 'Segredo de alto-mar');
        $this->as((string) Str::uuid())->postJson("/garrafas/$id/abrir")->assertConflict();
    }

    public function test_a_reply_carries_the_correspondence_and_waits_for_the_author(): void
    {
        $id = $this->launch($this->alice);
        $this->strand($id);
        $this->as($this->bento)->postJson("/garrafas/$id/abrir")->assertOk();
        $answer = ['letter' => 'Achei na Namíbia', 'signature' => 'Bento', 'glass' => 'ambar'];

        $this->as($this->alice)->postJson("/garrafas/$id/resposta", $answer)->assertForbidden();
        $reply = $this->as($this->bento)->postJson("/garrafas/$id/resposta", $answer)->assertCreated()->json('id');
        $this->as($this->bento)->postJson("/garrafas/$id/resposta", $answer)->assertConflict();

        $this->strand($reply);
        $this->as((string) Str::uuid())->postJson("/garrafas/$reply/abrir")->assertConflict();
        $opened = $this->as($this->alice)->postJson("/garrafas/$reply/abrir")->assertOk();
        $opened->assertJsonPath('letter', 'Achei na Namíbia')->assertJsonPath('correspondence.0.letter', 'Segredo de alto-mar');
    }

    public function test_after_a_year_a_reply_belongs_to_whoever_finds_it(): void
    {
        $id = $this->launch($this->alice);
        $this->strand($id);
        $this->as($this->bento)->postJson("/garrafas/$id/abrir");
        $reply = $this->as($this->bento)->postJson("/garrafas/$id/resposta", ['letter' => 'Resposta', 'glass' => 'azul'])->json('id');
        $this->strand($reply);
        Bottle::find($reply)->update(['ashore_at' => now()->subMinutes(366)]);

        $this->as((string) Str::uuid())->postJson("/garrafas/$reply/abrir")->assertOk();
    }

    public function test_the_tide_moves_bottles_and_writes_the_time_series(): void
    {
        $id = $this->launch($this->alice);
        $before = Bottle::find($id);
        $this->travel(90)->minutes();

        $result = app(Tide::class)->rise();
        $after = Bottle::find($id);

        $this->assertSame(1, $result['moved']);
        $this->assertNotEquals($before->position, $after->position);
        $this->assertEqualsWithDelta($after->status === Bottle::ASHORE ? $after->days : 90, $after->days, 0.1);
        $this->assertGreaterThan(5, count(app(Post::class)->track($after)));
        $this->assertSame(['type' => 'Point'], array_intersect_key($after->position, ['type' => 1]));
    }

    public function test_two_tides_at_once_do_not_write_two_tracks(): void
    {
        $this->launch($this->alice);
        $this->travel(60)->minutes();
        $rival = app(Tide::class);
        $inside = null;

        $outer = app(Tide::class)->rise(null, function () use ($rival, &$inside) {
            $inside ??= $rival->rise(); // outra maré tenta subir no meio desta
        });
        $samples = DB::connection('mongodb')->getDatabase()->selectCollection('drift_log')->countDocuments();

        $this->assertSame(1, $outer['moved']);
        $this->assertSame(0, $inside['moved']);
        $this->assertLessThanOrEqual(62, $samples);
    }

    public function test_small_tides_still_sample_once_per_day_at_sea(): void
    {
        $id = $this->launch($this->alice);
        $start = now();
        for ($k = 1; $k <= 36; $k++) { // 3 dias de mar em passos de 5 s
            app(Tide::class)->rise($start->copy()->addSeconds($k * 5));
        }
        $samples = DB::connection('mongodb')->getDatabase()->selectCollection('drift_log')->countDocuments();

        $this->assertEqualsWithDelta(3, Bottle::find($id)->days, 0.1);
        $this->assertThat($samples, $this->logicalAnd($this->greaterThanOrEqual(3), $this->lessThanOrEqual(5)));
    }

    public function test_the_distance_travelled_is_never_shorter_than_the_straight_line(): void
    {
        $start = now();
        foreach (range(1, 8) as $seed) {
            $bottle = app(Post::class)->launch($this->alice, 'De Dacar', null, 'verde', 14.7, -17.7);
            (new Tide(new Drifter(app(Ocean::class), $seed), app(Atlas::class)))->move($start->copy()->addMinutes(60));
            $chart = $bottle->refresh()->toChart($this->alice);

            $this->assertGreaterThanOrEqual($chart['straightKm'], $chart['km'], "semente $seed");
            $bottle->delete();
        }
    }

    /** A turbulência alonga o caminho quanto menor o passo: o percurso não pode depender do ritmo da maré. */
    public function test_the_distance_travelled_is_the_length_of_the_daily_track(): void
    {
        $this->travelTo($start = now()->startOfSecond());
        $bottle = app(Post::class)->launch($this->alice, 'De Dacar', null, 'verde', 14.7, -17.7);
        $tide = new Tide(new Drifter(app(Ocean::class), 3), app(Atlas::class));
        foreach (range(1, 40) as $k) {
            $tide->move($start->copy()->addSeconds($k * 5));
        }
        $bottle->refresh();

        $points = DB::connection('mongodb')->getDatabase()->selectCollection('drift_log')
            ->find(['bottle_id' => new ObjectId((string) $bottle->id)], ['sort' => ['ts' => 1]])->toArray();
        $line = [...array_map(fn ($p) => (array) $p['position']['coordinates'], $points), [$bottle->lng(), $bottle->lat()]];
        $length = 0.0;
        for ($i = 1; $i < count($line); $i++) {
            $length += Geo::distanceKm($line[$i - 1][1], $line[$i - 1][0], $line[$i][1], $line[$i][0]);
        }

        $this->assertGreaterThan(3, count($points));
        $this->assertEqualsWithDelta($length, $bottle->km, 0.01);
    }

    public function test_landing_clears_the_ttl_and_names_the_shore(): void
    {
        config(['ocean.landfall_chance' => 1.0, 'ocean.min_days_at_sea' => 0]);
        $id = $this->launch($this->alice, ['lat' => -23.15, 'lng' => -42.9]);
        // Semente fixa: sem ela, às vezes a corrente a leva para longe da praia.
        (new Tide(new Drifter(app(Ocean::class), 1), app(Atlas::class)))->rise(now()->addMinutes(120));
        $bottle = Bottle::find($id);

        $this->assertSame(Bottle::ASHORE, $bottle->status);
        $this->assertSame('Brasil', $bottle->shore);
        $this->assertNull($bottle->dissolves_at);
    }

    public function test_indexes_exist(): void
    {
        $indexes = collect(DB::connection('mongodb')->getDatabase()->selectCollection('bottles')->listIndexes());

        $this->assertTrue($indexes->contains(fn ($i) => $i->is2dSphere()));
        $this->assertTrue($indexes->contains(fn ($i) => $i->isTtl() && isset($i->getKey()['dissolves_at'])));
        $this->assertTrue($indexes->contains(fn ($i) => $i->isUnique() && isset($i->getKey()['parent_id'])));
    }

    public function test_the_atlas_prefers_the_smallest_sea(): void
    {
        $atlas = app(Atlas::class);
        $this->assertSame('Mar do Caribe', $atlas->sea(15, -75));
        $this->assertSame('Mar Mediterrâneo', $atlas->sea(34, 20));
        $this->assertSame('Pacífico Sul', $atlas->sea(-20, -120));
        $this->assertSame('Pacífico Norte', $atlas->sea(30, 179));
        $this->assertSame('Oceano Índico', $atlas->sea(-25, 80));
        $this->assertSame('Atlântico Norte', $atlas->sea(40, -40));
    }

    public function test_here_reports_current_and_neighbours(): void
    {
        $id = $this->launch($this->alice);
        $this->as($this->bento)->getJson('/mar/aqui?lat=-24.5&lng=-40')->assertOk()
            ->assertJsonPath('isSea', true)->assertJsonPath('sea', 'Atlântico Sul')->assertJsonPath('near.0.id', $id);
    }

    public function test_logbook_adds_up(): void
    {
        $this->launch($this->alice);
        $this->strand($this->launch($this->bento));
        $log = $this->getJson('/mar/diario')->assertOk()->json();

        $this->assertSame([1, 1, 0], [$log['drifting'], $log['ashore'], $log['opened']]);
        $this->assertSame(1, array_sum(array_column($log['ages'], 'n')));
        $this->assertSame([['name' => 'Namíbia', 'n' => 1]], $log['shores']);
    }

    public function test_unknown_bottles_are_404(): void
    {
        $this->getJson('/garrafas/nada')->assertNotFound();
        $this->getJson('/garrafas/64b000000000000000000000')->assertNotFound();
    }
}

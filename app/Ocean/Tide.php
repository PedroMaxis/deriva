<?php

namespace App\Ocean;

use App\Models\Bottle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/** Move todas as garrafas do último instante conhecido até agora, por mais longo que tenha sido o silêncio. */
final class Tide
{
    public function __construct(private readonly Drifter $drifter, private readonly Atlas $atlas) {}

    /**
     * Uma maré por vez: agendador, --watch e seeder disputam a mesma trava.
     *
     * @return array{moved: int, ashore: int}
     */
    public function rise(?Carbon $now = null, ?callable $onMove = null): array
    {
        $lock = Cache::lock('ocean:tide', 300);
        if (! $lock->get()) {
            return ['moved' => 0, 'ashore' => 0];
        }
        try {
            return $this->move($now, $onMove);
        } finally {
            $lock->release();
        }
    }

    /** Segura a maré enquanto $work mexe nas garrafas. */
    public static function hold(callable $work): void
    {
        Cache::lock('ocean:tide', 300)->block(60, $work);
    }

    /** @return array{moved: int, ashore: int} */
    public function move(?Carbon $now = null, ?callable $onMove = null): array
    {
        $now ??= now();
        $rate = config('ocean.days_per_minute') / 60; // dias de mar por segundo
        $writes = $samples = [];
        $ashore = 0;

        foreach (Bottle::where('status', Bottle::DRIFTING)->cursor() as $bottle) {
            $seconds = ($now->getTimestampMs() - $bottle->ticked_at->getTimestampMs()) / 1000;
            $days = min($seconds * $rate, config('ocean.dissolve_after_days') - $bottle->days);
            if ($days <= 0) {
                continue;
            }

            $id = new ObjectId((string) $bottle->id);
            $onMove && $onMove($bottle);
            $voyage = $this->drifter->advance($bottle->lat(), $bottle->lng(), $days, $bottle->days);
            $set = [
                'position' => Geo::point($voyage->lat, $voyage->lng),
                'u' => $voyage->u,
                'v' => $voyage->v,
                'days' => $bottle->days + $voyage->days,
                'ticked_at' => new UTCDateTime($now),
            ];

            // Amostra a cada dia de mar, mesmo quando a maré chega em passos menores que um dia.
            $track = $voyage->samples;
            $sinceSample = ($now->getTimestampMs() - $bottle->sampled_at->getTimestampMs()) / 1000 * $rate;
            if ($track === [] && $sinceSample >= 1) {
                $track = [['day' => $voyage->days, 'lat' => $voyage->lat, 'lng' => $voyage->lng, 'speed' => hypot($voyage->u, $voyage->v)]];
            }
            // O percurso é o comprimento da linha que o mapa desenha: de amostra em amostra, mais o trecho até aqui.
            // Somar cada passo não serve: com turbulência, quanto menor o passo, mais longo o caminho.
            $km = $bottle->sampled_km ?? $bottle->km;
            $last = $bottle->sampled_position ?? $bottle->position;
            foreach ($track as $sample) {
                $ts = $bottle->ticked_at->getTimestampMs() + (int) ($sample['day'] / $rate * 1000);
                $here = Geo::point($sample['lat'], $sample['lng']);
                $km += Geo::between($last, $here);
                $last = $here;
                $samples[] = [
                    'ts' => new UTCDateTime($ts),
                    'bottle_id' => $id,
                    'position' => $here,
                    'speed' => $sample['speed'],
                ];
                $set['sampled_at'] = new UTCDateTime($ts);
            }

            $set['sampled_km'] = $km;
            $set['sampled_position'] = $last;
            $set['km'] = $km + Geo::between($last, $set['position']);

            $update = ['$set' => $set];
            if ($voyage->ashore) {
                $ashore++;
                $update['$set'] += [
                    'status' => Bottle::ASHORE,
                    'shore' => $this->atlas->shore($voyage->lat, $voyage->lng),
                    'ashore_at' => new UTCDateTime($bottle->ticked_at->getTimestampMs() + (int) ($voyage->days / $rate * 1000)),
                ];
                $update['$unset'] = ['dissolves_at' => ''];
            }
            $writes[] = ['updateOne' => [['_id' => $id, 'status' => Bottle::DRIFTING, 'ticked_at' => new UTCDateTime($bottle->ticked_at)], $update]];
        }

        $db = DB::connection('mongodb')->getDatabase();
        if ($writes !== []) {
            $db->selectCollection('bottles')->bulkWrite($writes, ['ordered' => false]);
        }
        foreach (array_chunk($samples, 1000) as $chunk) {
            $db->selectCollection('drift_log')->insertMany($chunk, ['ordered' => false]);
        }

        return ['moved' => count($writes), 'ashore' => $ashore];
    }
}

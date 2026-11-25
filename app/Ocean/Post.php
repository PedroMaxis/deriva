<?php

namespace App\Ocean;

use App\Models\Bottle;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

/** O correio do mar: lançar, abrir, responder e reler. */
final class Post
{
    public function __construct(private readonly Ocean $ocean, private readonly Atlas $atlas) {}

    public function launch(string $sailor, string $letter, ?string $signature, string $glass, float $lat, float $lng, ?Bottle $inReplyTo = null): Bottle
    {
        $now = now();
        [$u, $v] = $this->ocean->current($lat, $lng);

        $bottle = Bottle::create([
            'letter' => $letter,
            'signature' => $signature,
            'glass' => $glass,
            'status' => Bottle::DRIFTING,
            'position' => Geo::point($lat, $lng),
            'launched_from' => Geo::point($lat, $lng),
            'launched_sea' => $this->atlas->sea($lat, $lng),
            'launched_at' => $now,
            'ticked_at' => $now,
            'sampled_at' => $now,
            'dissolves_at' => $now->copy()->addSeconds((int) (config('ocean.dissolve_after_days') / config('ocean.days_per_minute') * 60)),
            'u' => $u + Wind::leeway($lat),
            'v' => $v,
            'days' => 0.0,
            'km' => 0.0,
            'sender_key' => $sailor,
            ...($inReplyTo ? [
                'parent_id' => new ObjectId((string) $inReplyTo->id),
                'recipient_key' => $inReplyTo->sender_key,
            ] : []),
        ]);

        $this->collection('drift_log')->insertOne([
            'ts' => new UTCDateTime($now),
            'bottle_id' => new ObjectId((string) $bottle->id),
            'position' => Geo::point($lat, $lng),
            'speed' => hypot($u, $v),
        ]);

        return $bottle;
    }

    /** Só uma pessoa no mundo abre cada garrafa: o filtro e a escrita são uma única operação atômica. */
    public function open(Bottle $bottle, string $sailor): ?Bottle
    {
        $opened = $this->collection('bottles')->findOneAndUpdate(
            [
                '_id' => new ObjectId((string) $bottle->id),
                'status' => Bottle::ASHORE,
                'sender_key' => ['$ne' => $sailor],
                '$or' => [
                    ['recipient_key' => ['$in' => [null, $sailor]]],
                    ['ashore_at' => ['$lte' => new UTCDateTime(Bottle::reservationLimit())]],
                ],
            ],
            ['$set' => ['status' => Bottle::OPENED, 'opener_key' => $sailor, 'opened_at' => new UTCDateTime(now())]],
        );

        return $opened ? $bottle->refresh() : null;
    }

    /** @return array<int, array{lat: float, lng: float}> */
    public function track(Bottle $bottle): array
    {
        $cursor = $this->collection('drift_log')->aggregate([
            ['$match' => ['bottle_id' => new ObjectId((string) $bottle->id)]],
            ['$sort' => ['ts' => 1]],
            // No máximo 400 trechos, do primeiro ao último, seja a viagem de um mês ou de cinco anos.
            ['$bucketAuto' => ['groupBy' => '$ts', 'buckets' => 400, 'output' => ['position' => ['$first' => '$position.coordinates']]]],
            ['$sort' => ['_id.min' => 1]],
        ], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);

        $track = [];
        foreach ($cursor as $row) {
            $track[] = ['lat' => $row['position'][1], 'lng' => $row['position'][0]];
        }
        $track[] = ['lat' => $bottle->lat(), 'lng' => $bottle->lng()];

        return $track;
    }

    /** A correspondência que a garrafa carrega: da primeira carta até esta. */
    public function correspondence(Bottle $bottle): array
    {
        $row = $this->collection('bottles')->aggregate([
            ['$match' => ['_id' => new ObjectId((string) $bottle->id)]],
            ['$graphLookup' => [
                'from' => 'bottles',
                'startWith' => '$parent_id',
                'connectFromField' => 'parent_id',
                'connectToField' => '_id',
                'as' => 'ancestors',
                'depthField' => 'depth',
                'maxDepth' => 50,
            ]],
            ['$unwind' => '$ancestors'],
            ['$replaceWith' => '$ancestors'],
            ['$sort' => ['depth' => -1]],
            ['$project' => ['letter' => 1, 'signature' => 1, 'launched_sea' => 1, 'shore' => 1, 'days' => 1, 'km' => 1]],
        ], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);

        return array_map(fn (array $letter) => [
            'letter' => $letter['letter'],
            'signature' => $letter['signature'] ?? null,
            'from' => $letter['launched_sea'] ?? null,
            'to' => $letter['shore'] ?? null,
            'days' => (int) round($letter['days']),
            'km' => (int) round($letter['km']),
        ], iterator_to_array($row, false));
    }

    private function collection(string $name): Collection
    {
        return DB::connection('mongodb')->getDatabase()->selectCollection($name);
    }
}

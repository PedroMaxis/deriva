<?php

namespace App\Http\Controllers;

use App\Models\Bottle;
use App\Ocean\Atlas;
use App\Ocean\Geo;
use App\Ocean\Ocean;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeaController extends Controller
{
    private const ARRAYS = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

    /** Todas as garrafas visíveis no globo. Abertas, só as minhas. */
    public function index(Request $request): array
    {
        $sailor = $request->attributes->get('sailor');
        $bottles = Bottle::query()
            ->whereIn('status', [Bottle::DRIFTING, Bottle::ASHORE])
            ->orWhere(fn ($q) => $q->where('status', Bottle::OPENED)
                ->where(fn ($q) => $q->where('sender_key', $sailor)->orWhere('opener_key', $sailor)))
            ->orderBy('launched_at', 'desc')
            ->limit(3000)
            ->get();

        return [
            'daysPerMinute' => config('ocean.days_per_minute'),
            'bottles' => $bottles->map(fn (Bottle $b) => $b->toChart($sailor))->values()->all(),
        ];
    }

    /** O que há sob o cursor: mar, corrente e as garrafas mais próximas. */
    public function here(Request $request, Ocean $ocean, Atlas $atlas): array
    {
        $at = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        [$u, $v] = $ocean->current($at['lat'], $at['lng']);
        $sea = $ocean->isSea($at['lat'], $at['lng']);

        $near = DB::connection('mongodb')->getDatabase()->selectCollection('bottles')->aggregate([
            ['$geoNear' => [
                'near' => Geo::point($at['lat'], $at['lng']),
                'distanceField' => 'meters',
                'maxDistance' => 1_500_000,
                'query' => ['status' => ['$in' => [Bottle::DRIFTING, Bottle::ASHORE]]],
                'key' => 'position',
            ]],
            ['$limit' => 3],
            ['$project' => ['meters' => 1, 'status' => 1, 'glass' => 1]],
        ], self::ARRAYS);

        return [
            'isSea' => $sea,
            'sea' => $sea ? $atlas->sea($at['lat'], $at['lng']) : $atlas->shore($at['lat'], $at['lng']),
            'speed' => round(hypot($u, $v), 2),
            'heading' => Geo::compass($u, $v),
            'near' => array_map(fn (array $b) => [
                'id' => (string) $b['_id'], 'km' => (int) round($b['meters'] / 1000), 'status' => $b['status'], 'glass' => $b['glass'],
            ], iterator_to_array($near, false)),
        ];
    }

    /** Diário de bordo: um único $facet responde a tudo. */
    public function logbook(): array
    {
        $rows = DB::connection('mongodb')->getDatabase()->selectCollection('bottles')->aggregate([
            ['$facet' => [
                'status' => [['$group' => ['_id' => '$status', 'n' => ['$sum' => 1]]]],
                'sum' => [['$group' => ['_id' => null, 'km' => ['$sum' => '$km'], 'longest' => ['$max' => '$days']]]],
                'farthest' => [
                    ['$sort' => ['km' => -1]], ['$limit' => 5],
                    ['$project' => ['km' => 1, 'days' => 1, 'launched_sea' => 1, 'shore' => 1, 'status' => 1, 'glass' => 1]],
                ],
                'shores' => [
                    ['$match' => ['shore' => ['$ne' => null]]],
                    ['$group' => ['_id' => '$shore', 'n' => ['$sum' => 1]]],
                    ['$sort' => ['n' => -1, '_id' => 1]], ['$limit' => 6],
                ],
                'ages' => [
                    ['$match' => ['status' => Bottle::DRIFTING]],
                    ['$bucket' => ['groupBy' => '$days', 'boundaries' => [0, 30, 90, 180, 365, 730, 1826], 'default' => 'mais', 'output' => ['n' => ['$sum' => 1]]]],
                ],
            ]],
        ], self::ARRAYS)->toArray()[0];

        $status = array_column($rows['status'], 'n', '_id');
        $ages = array_column($rows['ages'], 'n', '_id');

        return [
            'drifting' => $status[Bottle::DRIFTING] ?? 0,
            'ashore' => $status[Bottle::ASHORE] ?? 0,
            'opened' => $status[Bottle::OPENED] ?? 0,
            'km' => (int) round($rows['sum'][0]['km'] ?? 0),
            'longestDays' => (int) round($rows['sum'][0]['longest'] ?? 0),
            'farthest' => array_map(fn (array $b) => [
                'id' => $b['status'] === Bottle::OPENED ? null : (string) $b['_id'],
                'km' => (int) round($b['km']), 'days' => (int) round($b['days']),
                'from' => $b['launched_sea'] ?? null, 'shore' => $b['shore'] ?? null, 'status' => $b['status'], 'glass' => $b['glass'],
            ], $rows['farthest']),
            'shores' => array_map(fn (array $s) => ['name' => $s['_id'], 'n' => $s['n']], $rows['shores']),
            'ages' => array_map(fn (array $a) => ['label' => $a[1], 'n' => $ages[$a[0]] ?? 0], [
                [0, 'até 1 mês'], [30, '1 a 3 meses'], [90, '3 a 6 meses'], [180, '6 a 12 meses'], [365, '1 a 2 anos'], [730, 'mais de 2 anos'],
            ]),
        ];
    }

    /**
     * Tempo real: change stream do MongoDB servido como SSE.
     * O resume token vira o id do evento; se a conexão cai, o navegador o devolve
     * em Last-Event-ID e o MongoDB continua do ponto exato.
     */
    public function events(Request $request): StreamedResponse
    {
        $token = $request->header('Last-Event-ID');

        return response()->stream(function () use ($token) {
            $bottles = DB::connection('mongodb')->getDatabase()->selectCollection('bottles');
            $pipeline = [
                ['$match' => ['$or' => [
                    ['operationType' => 'insert'],
                    ['operationType' => 'update', 'updateDescription.updatedFields.status' => ['$exists' => true]],
                ]]],
                ['$project' => [
                    'operationType' => 1,
                    'fullDocument.status' => 1, 'fullDocument.shore' => 1, 'fullDocument.launched_sea' => 1,
                    'fullDocument.days' => 1, 'fullDocument.km' => 1, 'fullDocument.position' => 1, 'fullDocument.glass' => 1,
                    'fullDocument._id' => 1, 'fullDocument.parent_id' => 1,
                ]],
            ];
            $options = ['fullDocument' => 'updateLookup', 'maxAwaitTimeMS' => 1500, ...self::ARRAYS];

            try {
                $stream = $bottles->watch($pipeline, $token && preg_match('/^[0-9A-Fa-f]+$/', $token)
                    ? $options + ['resumeAfter' => ['_data' => $token]] : $options);
            } catch (RuntimeException) { // token velho demais para o oplog
                $stream = $bottles->watch($pipeline, $options);
            }

            echo "retry: 1500\n\n";
            $this->flush();
            $until = microtime(true) + 25;

            for ($stream->rewind(); microtime(true) < $until && ! connection_aborted(); $stream->next()) {
                if (! $stream->valid()) {
                    echo ": mar calmo\n\n";
                    $this->flush();

                    continue;
                }
                $event = $stream->current();
                $document = $event['fullDocument'] ?? null;
                if ($document === null) {
                    continue;
                }
                echo 'id: '.$event['_id']['_data']."\n";
                echo 'data: '.json_encode([
                    'id' => (string) $document['_id'],
                    'status' => $document['status'],
                    'shore' => $document['shore'] ?? null,
                    'sea' => $document['launched_sea'] ?? null,
                    'days' => (int) round($document['days']),
                    'km' => (int) round($document['km']),
                    'isReply' => isset($document['parent_id']),
                ], JSON_UNESCAPED_UNICODE)."\n\n";
                $this->flush();
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}

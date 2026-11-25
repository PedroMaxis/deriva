<?php

namespace App\Models;

use App\Ocean\Geo;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class Bottle extends Model
{
    public const DRIFTING = 'drifting';

    public const ASHORE = 'ashore';

    public const OPENED = 'opened';

    public const GLASSES = ['verde', 'ambar', 'azul', 'claro'];

    protected $connection = 'mongodb';

    protected $table = 'bottles';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['letter', 'signature', 'sender_key', 'opener_key', 'recipient_key'];

    protected function casts(): array
    {
        return [
            'launched_at' => 'datetime',
            'ticked_at' => 'datetime',
            'sampled_at' => 'datetime',
            'ashore_at' => 'datetime',
            'opened_at' => 'datetime',
            'dissolves_at' => 'datetime',
        ];
    }

    public static function findOrAbort(string $id): self
    {
        abort_unless(preg_match('/^[0-9a-f]{24}$/', $id), 404, 'Essa garrafa não existe, ou já se desfez no mar.');

        return self::find(new ObjectId($id)) ?? abort(404, 'Essa garrafa não existe, ou já se desfez no mar.');
    }

    public function lat(): float
    {
        return $this->position['coordinates'][1];
    }

    public function lng(): float
    {
        return $this->position['coordinates'][0];
    }

    public function belongsToSailor(string $sailor): bool
    {
        return in_array($sailor, [$this->sender_key, $this->opener_key], true);
    }

    /** Uma resposta espera um ano de mar por quem escreveu a carta original; depois é de quem achar. */
    public function reservedFrom(string $sailor): bool
    {
        return $this->recipient_key !== null
            && $this->recipient_key !== $sailor
            && $this->ashore_at !== null
            && $this->ashore_at->greaterThan(self::reservationLimit());
    }

    public static function reservationLimit(): Carbon
    {
        return now()->subSeconds((int) (365 / config('ocean.days_per_minute') * 60));
    }

    /** O que todo mundo pode ver: posição e viagem, nunca a carta. */
    public function toChart(string $sailor): array
    {
        return [
            'id' => (string) $this->id,
            'lat' => $this->lat(),
            'lng' => $this->lng(),
            'u' => round($this->u ?? 0, 3),
            'v' => round($this->v ?? 0, 3),
            'days' => round($this->days, 1),
            'km' => (int) round($this->km),
            'glass' => $this->glass,
            'status' => $this->status,
            'shore' => $this->shore,
            'isReply' => $this->parent_id !== null,
            'mine' => $this->sender_key === $sailor,
            'forMe' => $this->recipient_key === $sailor,
            'reserved' => $this->reservedFrom($sailor),
            'launchedFrom' => ['lat' => $this->launched_from['coordinates'][1], 'lng' => $this->launched_from['coordinates'][0]],
            'launchedSea' => $this->launched_sea,
            'straightKm' => (int) round(Geo::between($this->launched_from, $this->position)),
        ];
    }
}

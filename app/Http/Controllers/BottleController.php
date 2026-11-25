<?php

namespace App\Http\Controllers;

use App\Models\Bottle;
use App\Ocean\Atlas;
use App\Ocean\Ocean;
use App\Ocean\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

class BottleController extends Controller
{
    public function __construct(private readonly Post $post, private readonly Ocean $ocean, private readonly Atlas $atlas) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->letterRules(),
            'lat' => ['required', 'numeric', 'between:-85,85'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ], $this->messages());

        if (! $this->ocean->isSea($data['lat'], $data['lng'])) {
            throw ValidationException::withMessages(['lat' => 'Isso é terra firme. Escolha um ponto no mar.']);
        }

        $sailor = $request->attributes->get('sailor');
        $bottle = $this->post->launch($sailor, $data['letter'], $data['signature'] ?? null, $data['glass'], $data['lat'], $data['lng']);

        return response()->json($bottle->toChart($sailor), 201);
    }

    public function show(Request $request, string $id): array
    {
        return $this->detail(Bottle::findOrAbort($id), $request->attributes->get('sailor'));
    }

    public function open(Request $request, string $id): JsonResponse
    {
        $sailor = $request->attributes->get('sailor');
        $bottle = Bottle::findOrAbort($id);
        $opened = $this->post->open($bottle, $sailor);

        if ($opened === null) {
            $bottle->refresh();

            return response()->json(['message' => match (true) {
                $bottle->sender_key === $sailor => 'Esta carta é sua. Quem abre é quem a encontra.',
                $bottle->status === Bottle::DRIFTING => 'Ela ainda está no mar. Só dá para abrir depois que encalhar.',
                $bottle->status === Bottle::OPENED => 'Alguém chegou antes: esta garrafa já foi aberta.',
                default => 'Esta é uma resposta e ainda espera por quem escreveu a primeira carta.',
            }], 409);
        }

        return response()->json($this->detail($opened, $sailor));
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $sailor = $request->attributes->get('sailor');
        $bottle = Bottle::findOrAbort($id);
        abort_unless($bottle->opener_key === $sailor, 403, 'Só quem abriu a garrafa pode responder.');

        $data = $request->validate($this->letterRules(), $this->messages());
        [$lat, $lng] = $this->ocean->nearestSea($bottle->lat(), $bottle->lng());

        try {
            $reply = $this->post->launch($sailor, $data['letter'], $data['signature'] ?? null, $data['glass'], $lat, $lng, $bottle);
        } catch (BulkWriteException $e) { // índice único em parent_id
            abort_if($e->getCode() === 11000 || str_contains($e->getMessage(), 'E11000'), 409, 'Esta carta já foi respondida.');
            throw $e;
        }

        return response()->json($reply->toChart($sailor), 201);
    }

    private function detail(Bottle $bottle, string $sailor): array
    {
        $readable = $bottle->belongsToSailor($sailor);
        $reply = Bottle::where('parent_id', new ObjectId((string) $bottle->id))->first();

        return [
            ...$bottle->toChart($sailor),
            'sea' => $bottle->status === Bottle::DRIFTING ? $this->atlas->sea($bottle->lat(), $bottle->lng()) : null,
            'track' => $this->post->track($bottle),
            'opener' => $bottle->opener_key === $sailor,
            'letter' => $readable ? $bottle->letter : null,
            'signature' => $readable ? $bottle->signature : null,
            'correspondence' => $readable ? $this->post->correspondence($bottle) : [],
            'replyId' => $readable && $reply ? (string) $reply->id : null,
        ];
    }

    private function letterRules(): array
    {
        return [
            'letter' => ['required', 'string', 'min:3', 'max:1500'],
            'signature' => ['nullable', 'string', 'max:60'],
            'glass' => ['required', Rule::in(Bottle::GLASSES)],
        ];
    }

    private function messages(): array
    {
        return [
            'letter.required' => 'A garrafa está vazia. Escreva alguma coisa.',
            'letter.min' => 'Escreva ao menos uma palavra inteira.',
            'letter.max' => 'A carta passou de 1500 caracteres e não cabe na garrafa.',
            'signature.max' => 'A assinatura cabe em até 60 caracteres.',
            'lat.required' => 'Escolha no mapa o ponto de lançamento.',
        ];
    }
}

<?php

namespace Database\Seeders;

use App\Models\Bottle;
use App\Ocean\Atlas;
use App\Ocean\Drifter;
use App\Ocean\Ocean;
use App\Ocean\Post;
use App\Ocean\Tide;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Cartas fictícias para o mar não começar vazio. Cada uma deriva de verdade desde o dia em que foi lançada. */
class DatabaseSeeder extends Seeder
{
    public function run(Post $post, Ocean $ocean): void
    {
        Tide::hold(fn () => $this->fillTheSea($post, $ocean));
    }

    private function fillTheSea(Post $post, Ocean $ocean): void
    {
        Bottle::query()->delete();
        DB::connection('mongodb')->getDatabase()->selectCollection('drift_log')->deleteMany([]);
        $present = now();

        foreach ($this->letters() as $k => [$lat, $lng, $days, $glass, $signature, $letter]) {
            [$lat, $lng] = $ocean->nearestSea($lat, $lng);
            Carbon::setTestNow($this->daysBefore($present, $days));
            $post->launch("ficticio-$k", $letter, $signature, $glass, $lat, $lng);
        }
        Carbon::setTestNow();
        (new Tide(new Drifter($ocean, 2026), app(Atlas::class)))->move($present);

        $this->correspondence($post, $ocean, $present);
    }

    /** Uma conversa já em andamento: carta aberta, respondida, e a resposta encalhada à espera de quem a ache. */
    private function correspondence(Post $post, Ocean $ocean, Carbon $present): void
    {
        $first = Bottle::where('status', Bottle::ASHORE)->where('signature', 'Iracema, faroleira aposentada')->first()
            ?? Bottle::where('status', Bottle::ASHORE)->first();
        if ($first === null) {
            return;
        }
        $first->update(['status' => Bottle::OPENED, 'opener_key' => 'ficticio-leitor', 'opened_at' => $present]);
        [$lat, $lng] = $ocean->nearestSea($first->lat(), $first->lng());

        for ($seed = 1; $seed < 60; $seed++) {
            $voyage = (new Drifter($ocean, $seed))->advance($lat, $lng, 900);
            if (! $voyage->ashore) {
                continue;
            }
            Carbon::setTestNow($this->daysBefore($present, $voyage->days + 400));
            $reply = $post->launch('ficticio-leitor',
                "Sua garrafa bateu na minha porta, que é um barranco de pedra. Li em voz alta para os dois cachorros, os únicos por perto. Um dormiu.\n\nAqui também havia uma luz. Trocaram por um poste com célula solar, ninguém precisa mais subir. Eu subo mesmo assim, às sextas. Da próxima vez levo o seu nome e grito lá de cima.",
                'Tomás', 'ambar', $lat, $lng, $first);
            Carbon::setTestNow();
            (new Tide(new Drifter($ocean, $seed), app(Atlas::class)))->move($present);
            $reply->refresh();
            if ($reply->status === Bottle::ASHORE) {
                $reply->update(['ashore_at' => $this->daysBefore($present, 390)]);

                return;
            }
            $reply->delete();
        }
    }

    private function daysBefore(Carbon $present, float $days): Carbon
    {
        return $present->copy()->subMilliseconds((int) ($days / config('ocean.days_per_minute') * 60_000));
    }

    /** lat, lng, dias no mar, vidro, assinatura, carta */
    private function letters(): array
    {
        return [
            [-12.9, -38.0, 410, 'verde', 'Iracema, faroleira aposentada', "Trinta e um anos acendendo a mesma luz para navio que nunca parou. Hoje o farol é automático e eu moro a duas quadras dele.\n\nÀs seis da tarde minha mão ainda procura a chave no bolso. Se você achou isto, acenda alguma coisa hoje. Uma vela, o fogão, qualquer luz. Considero serviço prestado."],
            [-22.9, -41.8, 260, 'ambar', 'Davi, 9 anos', "Oi! Eu queria saber se aí tem tubarão. Aqui meu tio disse que tem mas eu nunca vi. Se tiver escreve o tamanho.\n\nMeu cachorro chama Pastel."],
            [38.6, -9.6, 520, 'claro', 'Uma passageira do 28', "Escrevi esta carta no elétrico, com a letra a tremer nas curvas. Há onze anos que guardo um pedido de desculpa para o meu irmão e não sei para onde o mandar.\n\nEntão mando para aqui. Desculpa, Nuno. A casa era tua também."],
            [-33.9, 18.2, 340, 'azul', 'M.', 'Receita de minha avó, para não se perder: um quilo de marmelo, o mesmo de açúcar, e paciência até a colher ficar em pé sozinha. Ela nunca escreveu. Eu escrevi uma vez só, e é esta.'],
            [35.2, 140.9, 480, 'verde', 'Operador da linha Chōshi', "Último trem das 22h10, quase sempre vazio. Anuncio todas as estações mesmo assim.\n\nOntem uma senhora desceu e me agradeceu pelo nome das flores que eu disse junto com o nome das paradas. Achei que ninguém ouvia."],
            [40.3, -73.5, 190, 'claro', 'Ruth', 'Passei num concurso que eu nem queria e contei para todo mundo menos para a única pessoa que ia rir comigo. Estou contando para você. Ria, por favor.'],
            [-8.0, -34.6, 75, 'ambar', 'Seu Amaro da jangada', "Dou a previsão, que é o que sei fazer: lua virando, vento de terra até as nove, peixe só para quem acordar cedo.\n\nJoguei esta garrafa do ponto onde meu pai jogava a rede. A corrente aqui puxa para o norte, então ela vai ver mais mundo do que eu."],
            [-34.7, -54.9, 610, 'verde', null, 'Hoje eu disse não pela primeira vez no trabalho. Minha voz saiu fina, mas saiu.'],
            [21.2, -158.3, 300, 'azul', 'Kai', 'Se a onda trouxe até você, então ela chegou. Surfei essa mesma água, em outro lugar, em outro dia. Somos vizinhos de um jeito meio demorado.'],
            [57.0, -6.9, 220, 'ambar', 'Ilha de 14 pessoas', "Somos catorze. Em fevereiro fomos treze, depois nasceu a Ailsa e a conta voltou.\n\nA escola tem dois alunos e uma professora. Se alguém quiser vir, sobra uma carteira perto da janela."],
            [-17.6, -149.8, 380, 'claro', 'Teiva', 'Meu avô lia as ilhas pelo formato das nuvens. Eu leio pelo telefone. Estou tentando aprender com ele antes que a bateria dele acabe, se me entende.'],
            [14.7, -17.7, 440, 'verde', 'Awa, costureira', 'Guardo um retalho de cada vestido de noiva que fiz. São 212. Um dia costuro todos numa vela e saio navegando por aí.'],
            [-36.7, 175.2, 270, 'azul', null, "Coisas que perdi este ano: duas chaves, um guarda-chuva, a vergonha de dançar.\n\nSó procuro as chaves."],
            [43.2, 5.1, 150, 'ambar', 'Lucien, 81', 'Tenho 81 anos e comecei a aprender piano. Toco muito mal. O vizinho do lado bateu na parede e depois pediu para eu repetir.'],
            [10.6, -61.9, 95, 'verde', 'Marisol', 'Se você estiver triste, conte os azuis. Daqui eu vi sete antes do almoço.'],
            [-25.9, 33.2, 330, 'claro', 'Nelson, cartógrafo', "Desenho mapas de bairros que ainda não têm nome de rua. Pergunto às crianças como elas chamam cada beco e escrevo assim mesmo.\n\nExiste hoje, oficialmente, o Beco do Galo Que Não Canta."],
            [64.0, -22.9, 510, 'azul', 'Hildur', 'Aqui anoitece às três e meia da tarde. Escrevo de dentro do escuro com a lanterna na boca. O mar faz o mesmo barulho que no verão, isso me consola.'],
            [6.9, 79.6, 290, 'ambar', 'Professora Nimali', 'Pedi aos alunos uma pergunta que nenhum adulto soubesse responder. A melhor: "onde fica guardado o frio do verão?". Se souber, responda.'],
            [-54.9, -66.5, 700, 'verde', 'Tripulante do Aurora', "Cabo Horn, 4 da manhã, quarto de vigia. Um albatroz nos segue há dois dias sem bater as asas.\n\nNão tenho nada importante para dizer. Só queria que alguém soubesse do albatroz."],
            [-31.9, 115.4, 360, 'claro', 'J.', 'Doei os livros dela, menos um. É o que ela odiava. Assim ainda discutimos.'],
            [25.7, -79.9, 60, 'azul', 'Yolanda', 'Meu marido acha que fui comprar pão. Fui comprar pão. Mas antes passei na praia e joguei esta garrafa. Ninguém precisa saber de tudo.'],
            [-3.0, -38.3, 45, 'verde', 'Rendeira de Canoa', 'O ponto chama espinha de peixe. Minha filha não quis aprender, minha neta quis. Pulou uma geração, feito onda.'],
            [32.5, -16.9, 560, 'ambar', 'Padeiro da ilha', 'Há quarenta anos faço o mesmo pão e há quarenta anos ele sai um pouco diferente. Deixei de achar que era defeito.'],
            [-27.6, -48.2, 130, 'claro', 'Ana e Beto', 'Combinamos de jogar esta garrafa no dia em que vendêssemos a casa. Vendemos. Atrás da porta da cozinha ficaram as alturas das crianças. O comprador prometeu não pintar.'],
            [51.5, 2.2, 240, 'verde', 'Contêiner 4471', 'Trabalho no porto contando contêineres. Já contei mais de dois milhões. Nunca abri nenhum. Talvez por isso goste da ideia de você abrir esta garrafa.'],
            [18.9, 72.4, 420, 'azul', 'Farid', 'Minha mãe diz que quem escreve para desconhecido está escrevendo para Deus. Minha irmã diz que está escrevendo para si. Escolha, depois me conte.'],
            [-20.3, 57.2, 310, 'ambar', null, 'Plantei uma mangueira sabendo que não vou comer dela. É a primeira coisa adulta que faço.'],
            [47.2, -52.4, 200, 'claro', 'Pescador de St. John’s', 'O bacalhau está voltando, devagar. Meu filho diz que volta para o mar se ele voltar. Estamos todos esperando um peixe.'],
            [-40.0, -70.5, 280, 'verde', 'Clara', 'Aprendi a nadar com 46 anos. Ontem fui até onde não dá pé e fiquei lá um minuto inteiro, contando. Sessenta. Foi o melhor minuto do ano.'],
            [4.0, 7.0, 170, 'azul', 'Emeka', 'Toco trompete em enterro e em casamento. As mesmas três notas abrem os dois. Só muda o quanto a gente segura a última.'],
            [-16.3, -38.8, 25, 'ambar', 'Bia, oceanógrafa', 'Objeto flutuante nº 0 do meu doutorado. As outras têm GPS. Esta tem só isto: oi. Se achou, me diga onde, pelo jeito antigo.'],
            [28.5, -95.0, 480, 'claro', 'Turno da noite', 'Plataforma de petróleo, dia 19 de 28. A gente joga dominó apostando sobremesa. Estou devendo onze pudins.'],
            [12.2, 109.9, 370, 'verde', 'Linh', 'Meu pai atravessou este mar num barco de dez metros em 1979. Eu atravesso de avião, todo ano, na janela, olhando para baixo, procurando o barco.'],
            [-9.5, 13.0, 140, 'ambar', 'Avó Teresa', 'Ao rapaz ou moça que achar: come direito, dorme cedo, telefona à tua mãe. Não preciso de resposta. Preciso que faças.'],
        ];
    }
}

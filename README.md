# Deriva, o correio do mar

Você escreve uma carta, escolhe um ponto do oceano e lança a garrafa. Ela deriva num globo vivo,
levada por correntes calculadas, e um dia encalha. Só uma pessoa no mundo pode abri-la, e pode responder.

Um minuto aqui é um dia no mar.

## Rodar

```bash
./deriva          # http://localhost:8010
./deriva rede     # o mesmo, aberto à rede local e com debug desligado
./deriva test     # testes
./deriva artisan db:seed   # recomeça o mar com as cartas fictícias (apaga as garrafas atuais)
./deriva stop     # encerra o MongoDB
```

Nesta máquina PHP 8.5 e MongoDB 8.0 ficam em `.toolchain/`, fora do sistema e do Git. Em outra, o
script usa o `php` (8.3+, com `ext-mongodb`) e o `mongod` do PATH; antes rode `composer install`,
`cp .env.example .env`, `php artisan key:generate`, `npm install` e `npm run build`.

## As correntes

Nenhuma foi desenhada. `ocean:build` rasteriza os continentes e resolve a equação de Stommel

    r∇²ψ + β ∂ψ/∂x = rot τ

numa grade de 1°, com os ventos médios (alísios e ventos de oeste) como única força. Corrente do Golfo,
Kuroshio, do Brasil, Agulhas e Circumpolar Antártica aparecem sozinhas, e os testes cobram isso. A
garrafa soma a corrente, o arrasto do vento e turbulência. É um modelo climatológico simplificado, não
uma previsão oceanográfica.

## O que o MongoDB faz

| Recurso | Uso |
| --- | --- |
| Time series | `drift_log`: a trajetória de cada garrafa, com expiração |
| `2dsphere`, `$geoNear`, `$geoIntersects` | garrafa mais próxima, em que mar estou, em que costa encalhou |
| `findOneAndUpdate` | abertura atômica: uma carta, um leitor |
| Índice TTL | garrafa nunca achada se desfaz em 5 anos de mar |
| Índice único esparso | uma resposta por carta |
| Change streams | tempo real por SSE; o resume token é o `Last-Event-ID`, e quem cai continua do ponto exato |
| `$graphLookup` | a correspondência inteira que uma resposta carrega |
| `$facet`, `$bucket`, `$bucketAuto` | diário de bordo e redução das trajetórias |

## Privacidade

Não há cadastro. Um cookie cifrado identifica o marujo e o banco guarda apenas um HMAC dele. O texto
de uma carta só sai do servidor para quem a escreveu ou a abriu.

## Limites conhecidos

- Não há moderação de conteúdo além do limite de lançamentos por hora. Antes de abrir ao público, é preciso.
- Cada aba ocupa um worker do PHP com o SSE (em ciclos de 25 s). Com muita gente, use Octane ou FrankenPHP.
- `OCEAN_DAYS_PER_MINUTE` no `.env` muda a velocidade do mar.

Geografia: Natural Earth (domínio público). As cartas iniciais são fictícias.

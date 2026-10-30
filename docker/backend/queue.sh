#!/bin/sh
# Worker da fila de verificação do Stretor.
#
# A verificação por episódio leva minutos e não pode morar numa requisição: o
# modal enfileira (`VerificarTemporada`) e este processo é quem executa, gravando
# o selo de cada episódio conforme termina. O frontend acompanha pelo `GET` da
# temporada, então não há nada esperando a resposta aqui.
#
# Espera a sentinela de migrations do backend antes de começar: o job grava em
# `disponibilidades_episodios`, que o bootstrap do backend cria.
set -e

cd /var/www/backend

echo "[queue] Aguardando o bootstrap do backend..."

tentativas=0
while [ ! -f storage/framework/.migrations-done ]; do
  tentativas=$((tentativas + 1))

  if [ "$tentativas" -ge 150 ]; then
    echo "[queue] AVISO: sentinela de migrations ausente; iniciando de qualquer forma."
    break
  fi

  sleep 2
done

echo "[queue] Worker no ar (queue:work)."

# `--max-time` recicla o processo de hora em hora: o WebTorrent e o cliente HTTP
# seguram memória entre jobs, e num notebook de 8 GB isso pesa. O job em si tem
# teto próprio (`VerificarTemporada::$timeout`), maior que o de cada fonte.
exec php artisan queue:work \
  --queue=verificacao,default \
  --sleep=3 \
  --tries=1 \
  --timeout=3600 \
  --max-time=3600

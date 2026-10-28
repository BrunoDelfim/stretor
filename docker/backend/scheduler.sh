#!/bin/sh
# Agendador das rotinas diárias do Stretor.
#
# O container do backend roda php-fpm e não tem relógio: o `Schedule::command()`
# registrado em routes/console.php só dispara se alguém chamar `schedule:run` a
# cada minuto. Em dev isso não existe (não há cron) e na VPS exigia configurar o
# cron à mão — enquanto ninguém chamava, a verificação das fontes da Home nunca
# rodava e a Home ficava presa na última página verificada.
#
# Este processo é esse relógio: `schedule:work` fica dormindo e dispara o que
# estiver agendado. Ele espera a sentinela de migrations do backend antes de
# começar, porque a primeira rodada da verificação grava em tabelas que o
# bootstrap do backend cria.
set -e

cd /var/www/backend

echo "[scheduler] Aguardando o bootstrap do backend..."

tentativas=0
while [ ! -f storage/framework/.migrations-done ]; do
  tentativas=$((tentativas + 1))

  # Sem a sentinela em 5 minutos, algo deu errado no backend. O agendador sobe
  # mesmo assim: ficar de fora é menos grave do que ficar reiniciando em laço e
  # esconder o problema no log do container.
  if [ "$tentativas" -ge 150 ]; then
    echo "[scheduler] AVISO: sentinela de migrations ausente; iniciando de qualquer forma."
    break
  fi

  sleep 2
done

echo "[scheduler] Agendador no ar (schedule:work)."

exec php artisan schedule:work

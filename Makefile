.PHONY: up up-fast down restart logs ps build fresh reset-init

up:
	docker compose up -d --build

# Sobe sem rebuild e sem reexecutar o init (usa sentinela .bootstrap-done).
# Use quando nada mudou desde a última subida bem-sucedida.
up-fast:
	@if [ ! -f .bootstrap-done ]; then \
		echo "Sentinela .bootstrap-done ausente; rodando 'make up' completo."; \
		$(MAKE) up; \
	else \
		echo "Sentinela encontrada; subindo sem rebuild."; \
		docker compose up -d --no-recreate; \
	fi

down:
	docker compose down

# Força refazer o bootstrap na próxima subida.
reset-init:
	rm -f .bootstrap-done

restart:
	docker compose restart

logs:
	docker compose logs -f

ps:
	docker compose ps

build:
	docker compose build

fresh:
	rm -f .bootstrap-done
	docker compose down -v
	docker compose up -d --build

.PHONY: up up-fast down restart logs ps build fresh reset-init cache-clear prowlarr

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

# As listas de fontes ficam em cache por 30 min. Depois de mexer na busca do
# Torznab, limpe o cache para não testar com a resposta antiga guardada.
cache-clear:
	docker compose exec -T backend php artisan cache:clear

# Reexecuta o provisionamento do Prowlarr: descobre a chave da API e cadastra
# os indexadores públicos PT-BR. Útil depois de limpar o volume do Prowlarr
# (`make fresh`) ou ao adicionar uma definição nova em docker/prowlarr.
prowlarr:
	docker compose exec -T backend php artisan prowlarr:provisionar

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

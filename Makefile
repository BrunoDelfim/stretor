.PHONY: up down restart logs ps build fresh

up:
	docker compose up -d --build

down:
	docker compose down

restart:
	docker compose restart

logs:
	docker compose logs -f

ps:
	docker compose ps

build:
	docker compose build

fresh:
	docker compose down -v
	docker compose up -d --build

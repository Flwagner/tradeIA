COMPOSE=docker-compose

.PHONY: build up down restart logs shell console composer db-create db-migrate

build:
	$(COMPOSE) build

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

restart: down up

logs:
	$(COMPOSE) logs -f

shell:
	$(COMPOSE) exec php bash

console:
	$(COMPOSE) exec php php bin/console

composer:
	$(COMPOSE) exec php composer install

db-create:
	$(COMPOSE) exec php php bin/console doctrine:database:create --if-not-exists

db-migrate:
	$(COMPOSE) exec php php bin/console doctrine:migrations:migrate --no-interaction

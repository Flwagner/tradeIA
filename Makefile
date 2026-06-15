COMPOSE=docker-compose

.PHONY: build up down restart logs shell console composer db-create db-migrate market-import momentum-compute static-export static-deploy install-hooks phpstan cs-check cs-fix phpcs test quality

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

market-import:
	$(COMPOSE) exec php php bin/console app:market-data:import

momentum-compute:
	$(COMPOSE) exec php php bin/console app:momentum:compute

static-export:
	$(COMPOSE) exec php php bin/console app:static-export

static-deploy:
	COMPOSE=$(COMPOSE) bin/deploy-static

install-hooks:
	git config core.hooksPath .githooks

phpstan:
	$(COMPOSE) exec php composer phpstan

cs-check:
	$(COMPOSE) exec php composer cs-check

cs-fix:
	$(COMPOSE) exec php composer cs-fix

phpcs:
	$(COMPOSE) exec php composer phpcs

test:
	$(COMPOSE) exec php composer test

quality:
	$(COMPOSE) exec php composer quality

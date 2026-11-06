.DEFAULT_GOAL := help
.PHONY: help up down sh logs test qa db-roles migrate migrate-test seed check-compose

# .env.dev holds the dev Mercure keys; .env.local is git-ignored and overrides both (for example HTTP_PORT); later files win.
ENV_FILES := --env-file .env --env-file .env.dev $(if $(wildcard .env.local),--env-file .env.local)
# The image user must match the host user, or the bind-mounted /app is not writable on Linux.
export APP_UID ?= $(shell id -u)
export APP_GID ?= $(shell id -g)

COMPOSE := docker compose $(ENV_FILES)

# Dev-only database role passwords. The app and migrator ones must match the URLs in .env.dev and
# .env.test. The retention and readonly ones live only here, so no web or worker env holds them.
DB_ADMIN_USER ?= pinround
DB_APP_PASSWORD ?= change-me-dev-only-app-db
DB_MIGRATOR_PASSWORD ?= change-me-dev-only-migrator-db
DB_RETENTION_PASSWORD ?= change-me-dev-only-retention-db
DB_READONLY_PASSWORD ?= change-me-dev-only-readonly-db
PSQL := $(COMPOSE) exec -T postgres psql -v ON_ERROR_STOP=1 -U '$(DB_ADMIN_USER)'
# Quoted so the shell keeps a password with spaces or shell characters as one argument.
# Make still expands $ first (write $$), and a password with a single quote is not supported.
ROLES_SQL := -f /pinround/postgres/roles.sql \
	-v app_password='$(DB_APP_PASSWORD)' -v migrator_password='$(DB_MIGRATOR_PASSWORD)' \
	-v retention_password='$(DB_RETENTION_PASSWORD)' -v readonly_password='$(DB_READONLY_PASSWORD)'

help: ## List the targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

up: ## Build the image, start the stack, apply the database roles and migrate
	$(COMPOSE) build
	$(COMPOSE) up -d --wait
	$(MAKE) --no-print-directory db-roles migrate migrate-test

down: ## Stop the stack
	$(COMPOSE) down

sh: ## Open a shell in the app container
	$(COMPOSE) exec app bash

logs: ## Follow the logs
	$(COMPOSE) logs -f

test: migrate-test ## Run the PHPUnit tests in the app container
	$(COMPOSE) exec app bin/phpunit

qa: ## Run every quality gate in the app container
	$(COMPOSE) exec app composer qa

db-roles: ## Create pinround_test if missing and apply docker/postgres/roles.sql to both databases
	$(PSQL) -d pinround -tAc "SELECT 1 FROM pg_database WHERE datname = 'pinround_test'" | grep -q 1 \
		|| $(PSQL) -d pinround -c "CREATE DATABASE pinround_test"
	@echo "Applying docker/postgres/roles.sql to pinround and pinround_test (passwords not echoed)"
	@$(PSQL) -d pinround $(ROLES_SQL)
	@$(PSQL) -d pinround_test $(ROLES_SQL)

migrate: ## Run the database migrations on the dev database (as pinround_migrator)
	$(COMPOSE) exec app bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

migrate-test: ## Run the database migrations on the test database (as pinround_migrator)
	$(COMPOSE) exec -T -e APP_ENV=test app bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

check-compose: ## Validate compose.yaml and the service list
	sh tests/shell/compose-config-check.sh

seed: ## Load demo data (empty until P01)
	@echo "Nothing to seed yet: demo data arrives in P01."

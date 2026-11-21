.DEFAULT_GOAL := help
.PHONY: help demo up down sh logs test qa e2e e2e-update db-roles migrate migrate-test demo-shop seed check-compose

# .env.dev holds the dev Mercure keys; .env.local is git-ignored and overrides both (for example HTTP_PORT); later files win.
ENV_FILES := --env-file .env --env-file .env.dev $(if $(wildcard .env.local),--env-file .env.local)
# .env.dev.local is git-ignored and may hold DEMO_PASSWORD (plain KEY=value lines, no quotes). Reading it here lets the
# seed and e2e targets below see it, because the app container's own dotenv does not reach the host shell.
-include .env.dev.local
# The image user must match the host user, or the bind-mounted /app is not writable on Linux.
export APP_UID ?= $(shell id -u)
export APP_GID ?= $(shell id -g)

COMPOSE := docker compose $(ENV_FILES)

# Dev-only database role passwords. The app and migrator ones must match the URLs in .env.dev and
# .env.test, the readonly one the test-only URL in .env.test. The retention one lives only here,
# and no web or worker env holds the readonly or retention one.
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
	@grep -hE '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  %-14s %s\n", $$1, $$2}'

demo: ## A first look in about 5 minutes: ready-made images, demo mode on, the demo people and tickets
	bash .claude/skills/pinround-setup/pinround-setup.sh demo

up: ## Build the image, start the stack, apply the database roles and migrate
	$(COMPOSE) build
	@# The workers log in as pinround_app and read the outbox table, so on a fresh database they only turn
	@# healthy after the roles and migrations exist: start app and its dependencies first, the workers last.
	$(COMPOSE) up -d --wait app
	$(MAKE) --no-print-directory db-roles migrate migrate-test
	$(COMPOSE) up -d --wait

down: ## Stop the stack
	$(COMPOSE) down

sh: ## Open a shell in the app container
	$(COMPOSE) exec app bash

logs: ## Follow the logs
	$(COMPOSE) logs -f

test: migrate-test ## Run the PHPUnit tests in the app container
	@# A re-run test, not a required step: DatabaseRolesTest then proves roles.sql never widens a migration's grants.
	@$(PSQL) -q -d pinround_test $(ROLES_SQL)
	$(COMPOSE) exec app bin/phpunit

qa: ## Run every quality gate in the app container
	$(COMPOSE) exec app composer qa

# The tests log in as Lena with the password the seed gave her.
e2e: export E2E_PASSWORD ?= $(DEMO_PASSWORD)
e2e-update: export E2E_PASSWORD ?= $(DEMO_PASSWORD)

e2e: ## Run the Playwright tests (login, shell, components, gallery) against the seeded stack (E2E_BASE_URL, E2E_PASSWORD or DEMO_PASSWORD; E2E_ARGS="tests/e2e/x.spec.ts" runs only those specs)
	npm ci --prefix tests/e2e
	npm exec --prefix tests/e2e -- playwright install chromium
	npm exec --prefix tests/e2e -- playwright test --config tests/e2e/playwright.config.ts $(E2E_ARGS)

e2e-update: ## Refresh the Playwright screenshot baselines
	npm ci --prefix tests/e2e
	npm exec --prefix tests/e2e -- playwright install chromium
	npm exec --prefix tests/e2e -- playwright test --config tests/e2e/playwright.config.ts --update-snapshots $(E2E_ARGS)

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

demo-shop: ## Run the demo shop to test pinround on, at http://localhost:8090 (needs Node 20+; stop with Ctrl+C)
	cd demo-shop && node server.js

# --no-debug: in debug mode Doctrine and Messenger keep every query and message of the 2,000-person Crowd team in memory.
# A non-debug kernel never checks its cached container against the code, so the cache is rebuilt first or an old one runs.
seed: ## Load the demo people, organizations and teams into the dev database (needs DEMO_PASSWORD, 12+ characters; safe to re-run)
	$(COMPOSE) exec app bin/console cache:clear --no-debug
	$(COMPOSE) exec $(if $(DEMO_PASSWORD),-e DEMO_PASSWORD) app bin/console app:demo:seed --no-debug

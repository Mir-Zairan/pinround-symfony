.DEFAULT_GOAL := help
.PHONY: help up down sh logs test qa migrate seed

# .env.local is git-ignored and overrides .env (for example HTTP_PORT); later files win.
ENV_FILES := --env-file .env $(if $(wildcard .env.local),--env-file .env.local)
COMPOSE := docker compose $(ENV_FILES)

help: ## List the targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

up: ## Build the image and start the stack
	$(COMPOSE) build
	$(COMPOSE) up -d --wait

down: ## Stop the stack
	$(COMPOSE) down

sh: ## Open a shell in the app container
	$(COMPOSE) exec app bash

logs: ## Follow the logs
	$(COMPOSE) logs -f

test: ## Run the PHPUnit tests in the app container
	$(COMPOSE) exec app bin/phpunit

qa: ## Run every quality gate in the app container
	$(COMPOSE) exec app composer qa

migrate: ## Run the database migrations
	$(COMPOSE) exec app bin/console doctrine:migrations:migrate --no-interaction

seed: ## Load demo data (empty until P01)
	@echo "Nothing to seed yet: demo data arrives in P01."

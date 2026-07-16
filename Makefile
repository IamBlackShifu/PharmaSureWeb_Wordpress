# Makefile for PharmaSure WordPress Development

.PHONY: help up down restart logs build clean init test

help:
	@echo "PharmaSure WordPress Development Commands:"
	@echo ""
	@echo "Setup:"
	@echo "  make init              Initialize development environment"
	@echo "  make up                Start Docker services"
	@echo "  make down              Stop Docker services"
	@echo ""
	@echo "Development:"
	@echo "  make logs              View application logs"
	@echo "  make wp COMMAND=...    Run WP-CLI command (e.g., make wp COMMAND='plugin list')"
	@echo "  make mysql QUERY=...   Run MySQL query"
	@echo ""
	@echo "Database:"
	@echo "  make migrate           Run pending database migrations"
	@echo "  make backup            Backup database"
	@echo "  make restore FILE=...  Restore database from backup"
	@echo ""
	@echo "Testing:"
	@echo "  make test-isolation    Test tenant isolation"
	@echo "  make test-license      Test license validation"
	@echo ""
	@echo "Cleanup:"
	@echo "  make clean             Remove containers and volumes"
	@echo "  make rebuild           Rebuild all containers"

# Environment
COMPOSE := docker-compose
DC_EXEC := $(COMPOSE) exec -T

# Targets

up:
	$(COMPOSE) up -d
	@echo "Waiting for services to be ready..."
	@sleep 5
	@echo "✓ Services started"
	@echo ""
	@echo "Access:"
	@echo "  WordPress Admin: http://localhost:8080/wp-admin"
	@echo "  PHPMyAdmin: http://localhost:8081"
	@echo "  MailHog: http://localhost:8025"

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart

logs:
	$(COMPOSE) logs -f wordpress

logs-db:
	$(COMPOSE) logs -f db

logs-all:
	$(COMPOSE) logs -f

build:
	$(COMPOSE) build

init: up
	@echo "Initializing WordPress..."
	$(DC_EXEC) wpcli wp core install \
		--url=http://localhost:8080 \
		--title="PharmaSure" \
		--admin_user=admin \
		--admin_password=admin123 \
		--admin_email=admin@pharmasure.local \
		--skip-email || true
	@echo "✓ WordPress initialized"
	@echo ""
	@echo "Setting up Multisite..."
	$(DC_EXEC) wpcli wp core multisite-convert || true
	@echo "✓ Multisite enabled"

wp:
	$(DC_EXEC) wpcli wp $(COMMAND)

mysql:
	$(COMPOSE) exec -it db mysql -u pharmasure -ppharmasure_pass pharmasure -e "$(QUERY)"

migrate:
	$(DC_EXEC) wpcli wp pharmasure-core migrate

backup:
	@mkdir -p ./backups
	$(COMPOSE) exec db mysqldump -u pharmasure -ppharmasure_pass pharmasure > ./backups/backup-$$(date +%Y%m%d-%H%M%S).sql
	@echo "✓ Database backed up"

restore:
	@if [ -z "$(FILE)" ]; then \
		echo "Usage: make restore FILE=path/to/backup.sql"; \
		exit 1; \
	fi
	$(COMPOSE) exec -T db mysql -u pharmasure -ppharmasure_pass pharmasure < $(FILE)
	@echo "✓ Database restored"

test-isolation:
	$(DC_EXEC) wpcli wp pharmasure-core test-isolation

test-license:
	$(DC_EXEC) wpcli wp pharmasure-licensing test-license

clean:
	$(COMPOSE) down -v
	@echo "✓ Cleaned up"

rebuild: clean build up init
	@echo "✓ Full rebuild complete"

shell:
	$(COMPOSE) exec -it wordpress /bin/sh

shell-db:
	$(COMPOSE) exec -it db bash

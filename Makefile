.PHONY: help install test lint clean docker-build docker-run dev setup

# Default target
help:
	@echo "SuiteCRM MCP Server - Available commands:"
	@echo "  make install       Install PHP dependencies"
	@echo "  make test         Run PHPUnit tests"
	@echo "  make lint         Run PHP code linter"
	@echo "  make clean        Clean generated files"
	@echo "  make docker-build Build Docker image"
	@echo "  make docker-run   Run Docker container"
	@echo "  make dev          Start development environment"
	@echo "  make setup        Initial project setup"

# Install dependencies
install:
	composer install

# Run tests
test:
	./vendor/bin/phpunit

# Run PHP linter
lint:
	find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;

# Clean generated files
clean:
	rm -rf vendor/
	rm -f composer.lock
	rm -rf coverage/
	rm -rf .phpunit.result.cache

# Build Docker image
docker-build:
	docker build -t suitecrm-mcp-server:latest .

# Run Docker container
docker-run:
	docker run -it --rm \
		--env-file .env \
		suitecrm-mcp-server:latest

# Start development environment with SuiteCRM
dev:
	docker-compose --profile dev up -d
	@echo "SuiteCRM is starting at http://localhost:8080"
	@echo "Default credentials: admin / admin123"

# Stop development environment
dev-stop:
	docker-compose --profile dev down

# Initial setup
setup: install
	cp .env.example .env
	chmod +x bin/suitecrm-mcp-server
	@echo "Setup complete! Edit .env with your SuiteCRM credentials"

# Run the server locally
run:
	php suitecrm-mcp-server.php

# Update Composer dependencies
update:
	composer update

# Generate code coverage report
coverage:
	./vendor/bin/phpunit --coverage-html coverage

# Validate composer.json
validate:
	composer validate --strict

# Check for security vulnerabilities
security-check:
	composer audit
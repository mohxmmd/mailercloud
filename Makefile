.PHONY: help install setup test migrate serve load-test docker-up docker-down flink-submit outbox-worker

help:
	@echo "Available commands:"
	@echo "  make install       - Install composer dependencies"
	@echo "  make setup         - Copy .env, generate key, and run migrations"
	@echo "  make test          - Run automated PHPUnit / Pest test suite"
	@echo "  make serve         - Start local Laravel HTTP server"
	@echo "  make load-test     - Run 100k load test (80k unique, 20k duplicate)"
	@echo "  make docker-up     - Start Kafka and Apache Flink cluster"
	@echo "  make docker-down   - Stop Docker cluster"
	@echo "  make flink-submit  - Submit Flink SQL tumbling aggregation job"
	@echo "  make outbox-worker - Start continuous outbox publisher to Kafka"

install:
	cd task && composer install

setup:
	cd task && cp -n .env.example .env || true
	cd task && php artisan key:generate
	cd task && php artisan migrate

test:
	cd task && php artisan test

serve:
	cd task && php artisan serve --port=8000

load-test:
	cd task && php artisan submissions:load-test --total=100000 --duplicates=20000 --truncate

docker-up:
	docker compose up -d

docker-down:
	docker compose down

flink-submit:
	docker exec -i mailercloud-flink-jobmanager /opt/flink/bin/sql-client.sh -f /opt/flink/jobs/hourly_aggregation.sql

outbox-worker:
	cd task && php artisan outbox:publish --poll

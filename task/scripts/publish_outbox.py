#!/usr/bin/env python3
"""
Transactional Outbox Publisher for MailerCloud Submission API.
Reads unpublished events from MySQL outbox_events table and reliably publishes
them to Apache Kafka topic submission-events.
"""

import argparse
import json
import logging
import os
import signal
import sys
import time
from datetime import datetime

import pymysql
from kafka import KafkaProducer

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s'
)
logger = logging.getLogger(__name__)

running = True

def handle_signal(sig, frame):
    global running
    logger.info("Shutdown signal received. Stopping outbox publisher...")
    running = False

signal.signal(signal.SIGINT, handle_signal)
signal.signal(signal.SIGTERM, handle_signal)

def get_mysql_connection():
    # Host IP from WSL to Windows host or local
    db_host = os.environ.get('DB_HOST', '172.22.208.1')
    db_port = int(os.environ.get('DB_PORT', '3306'))
    db_user = os.environ.get('DB_USERNAME', 'root')
    db_pass = os.environ.get('DB_PASSWORD', '')
    db_name = os.environ.get('DB_DATABASE', 'mailer_cloud')

    return pymysql.connect(
        host=db_host,
        port=db_port,
        user=db_user,
        password=db_pass,
        database=db_name,
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False
    )

def create_kafka_producer(bootstrap_servers):
    return KafkaProducer(
        bootstrap_servers=bootstrap_servers.split(','),
        acks='all',
        retries=3,
        key_serializer=lambda k: k.encode('utf-8') if k else None,
        value_serializer=lambda v: json.dumps(v).encode('utf-8') if isinstance(v, dict) else v.encode('utf-8') if isinstance(v, str) else v
    )

def publish_batch(conn, producer, topic, batch_size):
    with conn.cursor() as cursor:
        cursor.execute(
            """
            SELECT id, event_id, aggregate_id, payload
            FROM outbox_events
            WHERE published_at IS NULL
            ORDER BY id ASC
            LIMIT %s
            """,
            (batch_size,)
        )
        events = cursor.fetchall()

    if not events:
        return 0

    published_ids = []

    for event in events:
        payload = event['payload']
        if isinstance(payload, str):
            try:
                payload_dict = json.loads(payload)
            except Exception:
                payload_dict = {}
        else:
            payload_dict = payload or {}

        form_id = payload_dict.get('form_id') or str(event['aggregate_id'])
        event_id = event['event_id']

        try:
            # Produce synchronously with broker ack
            future = producer.send(topic, key=form_id, value=payload_dict)
            future.get(timeout=10.0)
            published_ids.append(event['id'])
        except Exception as e:
            logger.error(f"Failed to publish event {event['id']}: {e}")
            with conn.cursor() as err_cursor:
                err_cursor.execute(
                    "UPDATE outbox_events SET retry_count = retry_count + 1, last_error = %s WHERE id = %s",
                    (str(e)[:500], event['id'])
                )
            conn.commit()
            break

    if published_ids:
        # Acknowledge published events in MySQL
        format_strings = ','.join(['%s'] * len(published_ids))
        with conn.cursor() as update_cursor:
            update_cursor.execute(
                f"UPDATE outbox_events SET published_at = NOW(), last_error = NULL WHERE id IN ({format_strings})",
                tuple(published_ids)
            )
        conn.commit()
        logger.info(f"Successfully published {len(published_ids)} event(s) to Kafka topic '{topic}'")

    return len(published_ids)

def main():
    parser = argparse.ArgumentParser(description='Outbox Publisher')
    parser.add_argument('--batch', type=int, default=100, help='Batch size')
    parser.add_argument('--poll', action='store_true', help='Continuous polling mode')
    parser.add_argument('--interval', type=int, default=1000, help='Poll interval in ms')
    parser.add_argument('--topic', default='submission-events', help='Kafka topic')
    parser.add_argument('--brokers', default='127.0.0.1:9092', help='Kafka bootstrap servers')

    args = parser.parse_args()

    logger.info(f"Starting Outbox Publisher (Kafka: {args.brokers}, Topic: {args.topic}, Batch: {args.batch})")

    producer = create_kafka_producer(args.brokers)
    conn = get_mysql_connection()

    try:
        while running:
            try:
                count = publish_batch(conn, producer, args.topic, args.batch)
                if count == 0:
                    if not args.poll:
                        logger.info("No more unpublished events. Exiting.")
                        break
                    time.sleep(args.interval / 1000.0)
            except (pymysql.MySQLError, Exception) as e:
                logger.error(f"Error in publishing loop: {e}")
                time.sleep(2.0)
                try:
                    conn = get_mysql_connection()
                except Exception:
                    pass
    finally:
        producer.flush()
        producer.close()
        conn.close()
        logger.info("Outbox publisher stopped cleanly.")

if __name__ == '__main__':
    main()

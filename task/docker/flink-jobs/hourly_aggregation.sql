SET 'execution.checkpointing.interval' = '5000';
SET 'execution.checkpointing.mode' = 'EXACTLY_ONCE';
SET 'state.backend' = 'hashmap';
SET 'state.checkpoints.dir' = 'file:///tmp/flink-checkpoints';
SET 'table.exec.sink.upsert-materialize' = 'NONE';

CREATE TABLE submission_events_source (
    `event_id` STRING,
    `event_type` STRING,
    `submission_id` BIGINT,
    `form_id` STRING,
    `idempotency_key` STRING,
    `email` STRING,
    `created_at` STRING,
    `event_time` AS TO_TIMESTAMP(SUBSTR(created_at, 1, 19), 'yyyy-MM-dd''T''HH:mm:ss'),
    WATERMARK FOR `event_time` AS `event_time` - INTERVAL '5' SECOND
) WITH (
    'connector' = 'kafka',
    'topic' = 'submission-events',
    'properties.bootstrap.servers' = 'kafka:29092',
    'properties.group.id' = 'flink-submission-aggregator-group',
    'scan.startup.mode' = 'earliest-offset',
    'format' = 'json',
    'json.fail-on-missing-field' = 'false',
    'json.ignore-parse-errors' = 'true'
);

CREATE TABLE hourly_submission_aggregates_sink (
    `form_id` STRING,
    `hour_start` TIMESTAMP(0),
    `submission_count` BIGINT,
    PRIMARY KEY (`form_id`, `hour_start`) NOT ENFORCED
) WITH (
    'connector' = 'jdbc',
    'url' = 'jdbc:mysql://host.docker.internal:3306/mailer_cloud?useSSL=false&allowPublicKeyRetrieval=true',
    'table-name' = 'hourly_submission_aggregates',
    'username' = 'flink',
    'password' = 'flinkpassword',
    'sink.buffer-flush.max-rows' = '1',
    'sink.buffer-flush.interval' = '1s'
);

INSERT INTO hourly_submission_aggregates_sink
SELECT
    form_id,
    TO_TIMESTAMP(DATE_FORMAT(event_time, 'yyyy-MM-dd HH:00:00')) AS hour_start,
    COUNT(DISTINCT event_id) AS submission_count
FROM submission_events_source
GROUP BY
    form_id,
    DATE_FORMAT(event_time, 'yyyy-MM-dd HH:00:00');

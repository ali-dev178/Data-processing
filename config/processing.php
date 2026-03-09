<?php

return [
    'alert_threshold' => env('ALERT_VALUE_THRESHOLD', '1000.00'),

    'kafka' => [
        'bootstrap_servers' => env('KAFKA_BOOTSTRAP_SERVERS', 'localhost:9092'),
        'input_topic'       => env('KAFKA_INPUT_TOPIC', 'data-records'),
        'consumer_group'    => env('KAFKA_CONSUMER_GROUP', 'data-processing-service'),
    ],
];

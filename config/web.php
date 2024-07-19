<?php

$params = require __DIR__ . '/params.php';

$config = [
    'id' => 'basic',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log', 'queue'],
    'defaultRoute' => 'event/index',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'bugsnag' => [
            'class' => 'app\components\BugsnagComponent',
            'apiKey' => '38348fad9ced4a74c0e35cfc39b6040c',
            'notifyReleaseStages' => ['production', 'development'],
        ],
        'redis' => [
            'class' => \yii\redis\Connection::class,
            'hostname' => env('REDIS_HOST'),
            'password' => env('REDIS_PASS'),
            'port' => env('REDIS_PORT'),
            'database' => 0,

            // retry connecting after connection has timed out
            // yiisoft/yii2-redis >=2.0.7 is required for this.
            'retries' => 1,
        ],
        'queue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis', // Redis connection component or its config
            'channel' => 'queue', // Queue channel key
        ],
        'request' => [
            // !!! insert a secret key in the following (if it is empty) - this is required by cookie validation
            'cookieValidationKey' => 'zNt-9hRKXdZCrGhhuD-iarlLYtQEBfdY',
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'user' => [
            'identityClass' => 'app\models\User',
            'enableAutoLogin' => true,
        ],
        'errorHandler' => [
            'errorAction' => 'event/error',
        ],
        'mailer' => [
            'class' => \yii\symfonymailer\Mailer::class,
            'viewPath' => '@app/mail',
            // send all mails to a file by default.
            'useFileTransport' => true,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
            ],
        ],
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
        'panels' => [
            'queue' => \yii\queue\debug\Panel::class,
        ],
        // uncomment the following to add your IP if you are not connecting from localhost.
        'allowedIPs' => ['127.0.0.1', '::1'],
    ];

    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];
}

return $config;

<?php

$autoloaders = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        require_once $autoloader;

        $yiiFiles = [
            dirname($autoloader) . '/yiisoft/yii2/Yii.php',
            dirname($autoloader, 2) . '/yiisoft/yii2/Yii.php',
        ];

        foreach ($yiiFiles as $yiiFile) {
            if (!class_exists('Yii', false) && is_file($yiiFile)) {
                require_once $yiiFile;
            }
        }

        return;
    }
}

throw new RuntimeException('Unable to locate Composer autoload.php for tests.');

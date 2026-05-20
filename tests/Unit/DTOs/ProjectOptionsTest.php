<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs;

use Roldante05\ScaffoldingFactory\DTOs\LaravelOptions;
use Roldante05\ScaffoldingFactory\DTOs\PhpVanillaOptions;

test('laravel options dto can be instantiated', function () {
    $options = new LaravelOptions(
        projectName: 'test-project',
        database: 'sqlite',
        withSail: true
    );

    expect($options->projectName)->toBe('test-project');
    expect($options->database)->toBe('sqlite');
    expect($options->withSail)->toBeTrue();
});

test('php vanilla options dto can be instantiated', function () {
    $options = new PhpVanillaOptions(
        projectName: 'test-vanilla',
        database: 'mysql',
        login: true,
        css: 'Tailwind CSS'
    );

    expect($options->projectName)->toBe('test-vanilla');
    expect($options->database)->toBe('mysql');
});

test('php vanilla options dto with sqlite', function () {
    $options = new PhpVanillaOptions(
        projectName: 'test-sqlite',
        database: 'sqlite',
        login: false,
        css: 'Bootstrap'
    );

    expect($options->database)->toBe('sqlite');
    expect($options->login)->toBeFalse();
});

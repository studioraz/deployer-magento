<?php

namespace SR\Deployer;

require_once 'recipe/common.php';

use Deployer\Exception\GracefulShutdownException;
use function Deployer\commandExist;
use function Deployer\currentHost;
use function Deployer\desc;
use function Deployer\fail;
use function Deployer\get;
use function Deployer\invoke;
use function Deployer\run;
use function Deployer\runLocally;
use function Deployer\set;
use function Deployer\task;
use function Deployer\test;
use function Deployer\testLocally;
use function Deployer\upload;
use function Deployer\which;
use function Deployer\writeln;

// Artifact deployment section

// The file the artifact is saved to
set('artifact_file', 'artifact.tar.zst');

// The directory the artifact is saved in
set('artifact_dir', 'artifacts');

// ── Variable‑based include / exclude definitions ─────────────────────────────
// Projects may modify these arrays in their own deploy.php with `add()` /
// `set()`; we generate temporary files from them just before packaging.

set('artifact_includes', [
    'app',
    'bin',
    'config',
    'generated',
    'lib',
    'pub',
    'setup',
    'vendor',
    'auth.json',
    'composer.json',
    'composer.lock'
]);

set('artifact_excludes', [
    '**/node_modules/**',
]);

// Helper to materialise an array into a temporary file and return the path
$createTempFile = static function (array $lines): string {
    // Create a secure tmp file on the control host
    $tmp = tempnam(sys_get_temp_dir(), 'dep_');
    // Write each pattern on its own line
    file_put_contents($tmp, implode(PHP_EOL, $lines) . PHP_EOL);
    return $tmp;
};

set('artifact_includes_file', fn() => $createTempFile(get('artifact_includes')));
set('artifact_excludes_file', fn() => $createTempFile(get('artifact_excludes')));
// ─────────────────────────────────────────────────────────────────────────────

// Set this value if "build_from_repo" is set to true. The target to deploy must also be set with "--branch", "--tag" or "--revision"
set('repository', null);

// The relative path to the artifact file. If the directory does not exist, it will be created
set('artifact_path', function () {
    if (!testLocally('[ -d {{artifact_dir}} ]')) {
        runLocally('mkdir -p {{artifact_dir}}');
    }
    return get('artifact_dir') . '/' . get('artifact_file');
});

// The location of the tar command. On MacOS you should have installed gtar, as it supports the required settings
set('bin/tar', function () {
    if (commandExist('gtar')) {
        return which('gtar');
    } else {
        return which('tar');
    }
});

desc('Packages all relevant files in an artifact.');
task('artifact:package', function () {
    // Ensure temporary include/exclude files exist (variables are lazily evaluated)
    get('artifact_excludes_file');
    get('artifact_includes_file');

    writeln('<info>Starting compression...</info>');

    // Build the tar command:
    //  - --posix: use portable format
    //  - --exclude: avoid including the archive itself
    //  - --exclude-from: additional patterns from your excludes file
    //  - -C: change into the release path
    //  - --files-from: include only the listed files
    //  - --use-compress-program="zstdmt": multi-threaded Zstd without extra flags
    $cmd = sprintf(
        '%s --posix --exclude=%s --exclude-from=%s -C %s -cf %s --files-from=%s '
        . '--use-compress-program="zstdmt"',
        '{{bin/tar}}',
        '{{artifact_path}}',
        '{{artifact_excludes_file}}',
        '{{release_or_current_path}}',
        '{{artifact_path}}',
        '{{artifact_includes_file}}'
    );

    // Execute it, streaming output to the console
    run($cmd);

    writeln('<info>Compression finished!</info>');
});

desc('Uploads artifact in release folder for extraction.');
task('artifact:upload', function () {
    upload(get('artifact_path'), '{{release_path}}');
});

desc('Extracts artifact in release path.');
task('artifact:extract', function () {
    run('{{bin/tar}} --zstd -xf {{release_path}}/{{artifact_file}} -C {{release_path}}');
    run('rm -rf {{release_path}}/{{artifact_file}}');
});


desc('Prepare local artifact build');
task('build:prepare', function () {
    if (!currentHost()->get('local')) {
        throw new GracefulShutdownException('Artifact can only be built locally, you provided a non local host');
    }

    $buildDir = '.';
    set('deploy_path', $buildDir);
    set('release_path', $buildDir);
    set('current_path', $buildDir);

});

desc('Builds an artifact.');
task('artifact:build', [
    'build:prepare',
    //'deploy:vendors',
    'build:magento:compile',
    'build:magento:assets',
    'build:artifact:package',
]);

desc('Build di generated code');
task('build:magento:compile', function () {
    if (test('[ ! -d {{release_or_current_path}}/{{magento_dir}}/generated/code ]')) {
        run('{{bin/php}} {{bin/magento}} setup:di:compile');
    } else {
        writeln('<info>Generated cache found. Skipping compilation.</info>');
    }
});

// Deploy static assets for Magento
desc('Build static assets');
task('build:magento:assets', function () {
    invoke('magento:deploy:assets');
});


desc('Prepares an artifact on the target server');
task('artifact:prepare', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'artifact:upload',
    'artifact:extract',
    'deploy:additional-shared',
    'deploy:shared',
    'deploy:writable',
]);

desc('Executes the tasks after artifact is released');
task('artifact:finish', [
    'magento:cache:flush',
    'cachetool:clear:opcache',
    'deploy:cleanup',
    'deploy:unlock',
    'deploy:success',
]);

desc('Actually releases the artifact deployment');
task('artifact:deploy', [
    'artifact:prepare',
    'magento:maintenance:enable-if-needed',
    'magento:config:import',
    'magento:upgrade:db',
    'magento:maintenance:disable',
    'deploy:symlink',
    'artifact:finish',
]);

// Build artifact package: ensure artifact directory exists before packaging
desc('Prepare artifact folder and package');
task('build:artifact:package', function () {
    // If artifact directory already exists, skip packaging
    if (test('[ -d {{artifact_dir}} ]')) {
        writeln('<comment>Artifact directory already exists; skipping package step.</comment>');
        return;
    }
    // Create artifact directory
    run('mkdir -p {{artifact_dir}}');
    // Invoke the artifact packaging task
    invoke('artifact:package');
});

fail('artifact:deploy', 'deploy:failed');

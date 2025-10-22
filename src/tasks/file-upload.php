<?php

namespace SR\Deployer;

use function Deployer\desc;
use function Deployer\task;
use function Deployer\run;
use function Deployer\runLocally;
use function Deployer\upload;
use function Deployer\download;
use function Deployer\test;
use function Deployer\testLocally;
use function Deployer\currentHost;
use function Deployer\writeln;
use function Deployer\get;

/**
 * Variables:
 *   src  - local source path
 *   dest - remote destination path
 *   pre  - (optional) command to run before transfer
 *   post - (optional) command to run after transfer
 */

/**
 * Upload file or folder to remote host.
 *
 * Example:
 * dep file:upload -o src=./build/config.json -o dest=/var/www/shared/config.json
 */
desc('Upload file to remote');
task('file:upload', function () {
    $src  = trim((string) get('src', ''));
    $dest = trim((string) get('dest', ''));
    $pre  = trim((string) get('pre', ''));
    $post = trim((string) get('post', ''));

    if ($src === '' || $dest === '') {
        writeln('<error>[file:up] Missing vars: src and/or dest</error>');
        return;
    }

    if (!testLocally('[ -f ' . escapeshellarg($src) . ' ]')) {
        writeln("<error>[file:up] Local file not found: {$src}</error>");
        return;
    }

    if ($pre !== '') {
        writeln("<info>[file:up] Running pre-cmd: {$pre}</info>");
        run($pre);
    }

    run('mkdir -p ' . escapeshellarg(dirname($dest)));
    writeln("<info>[file:up] Uploading {$src} → {$dest}</info>");
    upload($src, $dest);

    if ($post !== '') {
        writeln("<info>[file:up] Running post-cmd: {$post}</info>");
        run($post);
    }

    writeln('<info>[file:up] Done.</info>');
});

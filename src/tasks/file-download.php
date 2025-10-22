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
 * Download file or folder from remote host.
 *
 * Example:
 * dep file:downlaod -o src=/var/www/shared/export.json -o dest=./downloads/export.json
 */
desc('Download file from remote');
task('file:downlaod', function () {
    $src  = trim((string) get('src', ''));
    $dest = trim((string) get('dest', ''));
    $pre  = trim((string) get('pre', ''));
    $post = trim((string) get('post', ''));

    if ($src === '' || $dest === '') {
        writeln('<error>[file:down] Missing vars: src and/or dest</error>');
        return;
    }

    if (!test('[ -f ' . escapeshellarg($src) . ' ]')) {
        writeln("<error>[file:down] Remote file not found: {$src}</error>");
        return;
    }

    if ($pre !== '') {
        writeln("<info>[file:down] Running pre-cmd: {$pre}</info>");
        run($pre);
    }

    runLocally('mkdir -p ' . escapeshellarg(dirname($dest)));
    writeln("<info>[file:down] Downloading {$src} → {$dest}</info>");
    download($src, $dest);

    if ($post !== '') {
        writeln("<info>[file:down] Running post-cmd: {$post}</info>");
        run($post);
    }

    writeln('<info>[file:down] Done.</info>');
});

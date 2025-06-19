<?php

namespace SR\Deployer;

use function Deployer\desc;
use function Deployer\get;
use function Deployer\invoke;
use function Deployer\run;
use function Deployer\test;
use function Deployer\task;
use function Deployer\writeln;
use Symfony\Component\Console\Output\OutputInterface;

desc('Rearranges shared folder structure if it only contains a single "src" directory');
task('migration:shared:rearrange', function () {
    $shared = get('deploy_path') . '/shared';

    // Ensure there's only one subdirectory and it is named 'src'
    $entries = explode("\n", run("ls -1 $shared"));
    if (count($entries) !== 1 || trim($entries[0]) !== 'src') {
        writeln('<notice>❌ shared directory must contain only a single "src" folder. Aborting.</notice>');
        return;
    }

    writeln('📂 Contents of shared/src:');
    run("ls -1 $shared/src");

    writeln('➡ Moving contents from shared/src to shared...');
    run("mv $shared/src/* $shared/ && rmdir $shared/src");

    writeln('📂 New contents of shared:');
    run("ls -1 $shared");

    writeln('⚙️ Invoking migration:deploy:shared to apply symlinks...');
    writeln('🔁 Re-symlinking shared items into current release...');
    invoke('migration:deploy:shared');

    writeln('<info>✅ Shared structure rearranged and re-symlinked successfully.</info>');
});

desc('Migration-specific symlinks for shared files and dirs');
task('migration:deploy:shared', function () {
    $sharedPath = get('deploy_path') . '/shared';
    $releasePath = get('deploy_path') . '/current';
    // Determine actual release base (account for src)
    $releaseBase = test("[ -d $releasePath/src ]") ? "$releasePath/src" : $releasePath;

    $copyVerbosity = output()->getVerbosity() === OutputInterface::VERBOSITY_DEBUG ? 'v' : '';

    // Shared directories
    foreach (get('shared_dirs') as $dir) {
        $dir = trim($dir, '/');
        if (!test("[ -d $sharedPath/$dir ]")) {
            run("mkdir -p $sharedPath/$dir");
            if (test("[ -d $releaseBase/$dir ]")) {
                run("cp -r$copyVerbosity $releaseBase/$dir $sharedPath/" . dirname($dir));
            }
        }
        run("rm -rf $releaseBase/$dir");
        run("mkdir -p `dirname $releaseBase/$dir`");
        run("{{bin/symlink}} $sharedPath/$dir $releaseBase/$dir");
    }

    // Shared files
    foreach (get('shared_files') as $file) {
        $dirname = dirname(parse($file));
        if (!test("[ -d $sharedPath/$dirname ]")) {
            run("mkdir -p $sharedPath/$dirname");
        }
        if (!test("[ -f $sharedPath/$file ]") && test("[ -f $releaseBase/$file ]")) {
            run("cp -r$copyVerbosity $releaseBase/$file $sharedPath/$file");
        }
        run("if [ -f $releaseBase/$file ]; then rm -rf $releaseBase/$file; fi");
        run("if [ ! -d $releaseBase/$dirname ]; then mkdir -p $releaseBase/$dirname; fi");
        run("[ -f $sharedPath/$file ] || touch $sharedPath/$file");
        run("{{bin/symlink}} $sharedPath/$file $releaseBase/$file");
    }
})->hidden();


desc('Dry-run of migration:shared:rearrange to preview actions');
task('migration:shared:dry-run', function () {
    $shared = get('deploy_path') . '/shared';

    // Preview listing of shared/src contents
    writeln('📂 [Dry Run] Contents of shared/src:');
    writeln(run("ls -1 $shared/src"));

    // Preview move command
    writeln('➡ [Dry Run] Would execute: mv ' . $shared . '/src/* ' . $shared . '/ && rmdir ' . $shared . '/src');

    // Preview new shared folder contents
    writeln('📂 [Dry Run] After move, shared contents would be:');
    writeln(run("ls -1 $shared"));

    // Preview symlink actions
    writeln('⚙️ [Dry Run] Would invoke migration:deploy:shared to apply symlinks (dry run)');
    // Instead of invoke, show actual symlink commands
    $releasePath = get('deploy_path') . '/current';
    $releaseBase = test("[ -d $releasePath/src ]") ? $releasePath . '/src' : $releasePath;
    foreach (get('shared_dirs') as $dir) {
        $dir = trim($dir, '/');
        writeln('[Dry Run] {{bin/symlink}} ' . $shared . '/' . $dir . ' ' . $releaseBase . '/' . $dir);
    }
    foreach (get('shared_files') as $file) {
        writeln('[Dry Run] {{bin/symlink}} ' . $shared . '/' . $file . ' ' . $releaseBase . '/' . $file);
    }
});

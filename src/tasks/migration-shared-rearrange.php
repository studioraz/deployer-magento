<?php

namespace SR\Deployer;

use function Deployer\desc;
use function Deployer\get;
use function Deployer\has;
use function Deployer\invoke;
use function Deployer\run;
use function Deployer\test;
use function Deployer\task;
use function Deployer\writeln;
use function Deployer\output;
use function Deployer\parse;
use Symfony\Component\Console\Output\OutputInterface;

desc('Rearranges shared folder structure if it only contains a single "src" directory');
task('migration:shared:rearrange', function () {
    $shared = get('deploy_path') . '/shared';

    writeln('💾 Creating media backup before any changes...');
    invoke('migration:media:backup');

    // Ensure there's only one subdirectory and it is named 'src'
    $entries = explode("\n", run("ls -1 $shared"));
    $hasSingleSrc = count($entries) === 1 && trim($entries[0]) === 'src';
    if ($hasSingleSrc) {
        writeln('📂 Contents of shared/src:');
        run("ls -1 $shared/src");

        writeln('➡ Moving contents from shared/src to shared...');
        run("mv $shared/src/* $shared/ && rmdir $shared/src");

        writeln('📂 New contents of shared:');
        run("ls -1 $shared");
    } else {
        writeln('<comment>ℹ️ shared directory is not a single "src" folder. Skipping rearrange step.</comment>');
    }

    writeln('⚙️ Invoking migration:deploy:shared to apply symlinks...');
    writeln('🔁 Re-symlinking shared items into current release...');
    invoke('migration:deploy:shared');

    writeln('🧰 Updating crontab and logrotate paths (if present)...');
    invoke('migration:paths:update');


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
});


desc('Update crontab and logrotate paths after shared/src flattening');
task('migration:paths:update', function () {
    $deployPath = get('deploy_path');

    $cronReplacements = has('migration_cron_replacements')
        ? get('migration_cron_replacements')
        : [
            $deployPath . '/current/src/' => $deployPath . '/current/',
        ];

    $logrotateReplacements = has('migration_logrotate_replacements')
        ? get('migration_logrotate_replacements')
        : [
            $deployPath . '/shared/src/' => $deployPath . '/shared/',
        ];

    $logrotateFiles = has('migration_logrotate_files')
        ? (array) get('migration_logrotate_files')
        : [$deployPath . '/logrotate.conf'];

    // Update crontab paths if crontab exists.
    $crontab = run('crontab -l || true');
    if (trim($crontab) !== '') {
        $updatedCrontab = $crontab;
        foreach ($cronReplacements as $from => $to) {
            $updatedCrontab = str_replace($from, $to, $updatedCrontab);
        }

        if ($updatedCrontab !== $crontab) {
            $updatedCrontab = rtrim($updatedCrontab, "\n") . "\n";
            run("printf %s " . escapeshellarg($updatedCrontab) . " | crontab -");
            writeln('<info>✅ Crontab updated.</info>');
        } else {
            writeln('<comment>ℹ️ Crontab already up to date.</comment>');
        }
    } else {
        writeln('<comment>ℹ️ No crontab found for current user.</comment>');
    }

    // Update logrotate config paths when file exists.
    foreach ($logrotateFiles as $logrotateFile) {
        if (!test("[ -f $logrotateFile ]")) {
            writeln("<comment>ℹ️ Logrotate file not found: $logrotateFile</comment>");
            continue;
        }

        $logrotateContent = run("cat $logrotateFile");
        $updatedLogrotate = $logrotateContent;
        foreach ($logrotateReplacements as $from => $to) {
            $updatedLogrotate = str_replace($from, $to, $updatedLogrotate);
        }

        if ($updatedLogrotate !== $logrotateContent) {
            $updatedLogrotate = rtrim($updatedLogrotate, "\n") . "\n";
            run("printf %s " . escapeshellarg($updatedLogrotate) . " | tee $logrotateFile > /dev/null");
            writeln("<info>✅ Logrotate updated: $logrotateFile</info>");
        } else {
            writeln("<comment>ℹ️ Logrotate already up to date: $logrotateFile</comment>");
        }
    }
});


desc('Backup pub/media from shared/src to deploy_path');
task('migration:media:backup', function () {
    $deployPath = get('deploy_path');
    $mediaSource = $deployPath . '/shared/src/pub/media';
    $timestamp   = run('date +%Y%m%d_%H%M%S');
    $backupFile  = $deployPath . '/media_backup_' . trim($timestamp) . '.tar.gz';

    if (!test("[ -d $mediaSource ]")) {
        throw new \RuntimeException("❌ Media source directory not found: $mediaSource");
    }

    writeln("📦 Creating media backup from: $mediaSource");
    writeln("📁 Destination: $backupFile");

    run("tar -czf $backupFile -C " . dirname($mediaSource) . " " . basename($mediaSource));

    if (!test("[ -f $backupFile ]")) {
        throw new \RuntimeException('❌ Backup file was not created.');
    }

    $sizeBytes = (int) run("stat -c%s $backupFile");
    $sizeMB    = round($sizeBytes / 1024 / 1024, 2);

    if ($sizeBytes === 0) {
        throw new \RuntimeException('❌ Backup file was created but is empty (0 bytes).');
    }

    writeln("<info>✅ Media backup created successfully: $backupFile ({$sizeMB} MB)</info>");
});


desc('Dry-run of migration:shared:rearrange to preview actions');
task('migration:paths:dry-run', function () {
    $deployPath = get('deploy_path');

    $cronReplacements = has('migration_cron_replacements')
        ? get('migration_cron_replacements')
        : [
            $deployPath . '/current/src/' => $deployPath . '/current/',
        ];

    $logrotateReplacements = has('migration_logrotate_replacements')
        ? get('migration_logrotate_replacements')
        : [
            $deployPath . '/shared/src/' => $deployPath . '/shared/',
        ];

    $logrotateFiles = has('migration_logrotate_files')
        ? (array) get('migration_logrotate_files')
        : [$deployPath . '/logrotate.conf'];

    $crontab = run('crontab -l || true');
    if (trim($crontab) !== '') {
        $updatedCrontab = $crontab;
        foreach ($cronReplacements as $from => $to) {
            $updatedCrontab = str_replace($from, $to, $updatedCrontab);
        }

        if ($updatedCrontab !== $crontab) {
            writeln('<info>🧰 [Dry Run] Crontab changes:</info>');
            $oldLines = explode("\n", $crontab);
            $newLines = explode("\n", $updatedCrontab);
            $max = max(count($oldLines), count($newLines));
            for ($i = 0; $i < $max; $i++) {
                $old = $oldLines[$i] ?? '';
                $new = $newLines[$i] ?? '';
                if ($old !== $new) {
                    if (trim($old) !== '') {
                        writeln('- ' . $old);
                    }
                    if (trim($new) !== '') {
                        writeln('+ ' . $new);
                    }
                }
            }
        } else {
            writeln('<comment>ℹ️ [Dry Run] Crontab already up to date.</comment>');
        }
    } else {
        writeln('<comment>ℹ️ [Dry Run] No crontab found for current user.</comment>');
    }

    foreach ($logrotateFiles as $logrotateFile) {
        if (!test("[ -f $logrotateFile ]")) {
            writeln("<comment>ℹ️ [Dry Run] Logrotate file not found: $logrotateFile</comment>");
            continue;
        }

        $logrotateContent = run("cat $logrotateFile");
        $updatedLogrotate = $logrotateContent;
        foreach ($logrotateReplacements as $from => $to) {
            $updatedLogrotate = str_replace($from, $to, $updatedLogrotate);
        }

        if ($updatedLogrotate !== $logrotateContent) {
            writeln("<info>🧰 [Dry Run] Logrotate changes for $logrotateFile:</info>");
            $oldLines = explode("\n", $logrotateContent);
            $newLines = explode("\n", $updatedLogrotate);
            $max = max(count($oldLines), count($newLines));
            for ($i = 0; $i < $max; $i++) {
                $old = $oldLines[$i] ?? '';
                $new = $newLines[$i] ?? '';
                if ($old !== $new) {
                    if (trim($old) !== '') {
                        writeln('- ' . $old);
                    }
                    if (trim($new) !== '') {
                        writeln('+ ' . $new);
                    }
                }
            }
        } else {
            writeln("<comment>ℹ️ [Dry Run] Logrotate already up to date: $logrotateFile</comment>");
        }
    }
});


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

    writeln('🧰 [Dry Run] Would update crontab and logrotate paths (if present)');
    invoke('migration:paths:dry-run');
});

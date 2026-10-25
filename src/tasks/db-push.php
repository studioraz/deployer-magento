<?php

namespace SR\Deployer;

use Deployer\Exception\GracefulShutdownException;

use function Deployer\desc;
use function Deployer\task;
use function Deployer\run;
use function Deployer\invoke;
use function Deployer\upload;
use function Deployer\testLocally;
use function Deployer\writeln;
use function Deployer\get;
use function Deployer\currentHost;

desc(<<<'DESC'
Import a local database dump into a remote (non-production) environment.

Usage:
  dep db:push <environment> -o src=<path/to/dump.sql.gz> [options]

This task will:
  • Optionally back up the remote database to {{deploy_path}}/backups.
  • Upload the dump and import it with n98-magerun2 (maintenance mode on).
  • Re-apply the environment config with config:data:import (config/store/<environment>).
  • Clear cron_schedule so jobs scheduled on the source don't run here.
  • Run setup:upgrade --keep-generated and flush the cache.

Variables:
  src         (string) Local gzip dump to import. Required.
  backup      (bool)   Back up the remote database first. Default: true.
  backup_keep (int)    Number of backups to keep. Default: 3.

Examples:
  dep db:pull production -o import=false -o dest=./.db/prod.sql.gz
  dep db:push staging -o src=./.db/prod.sql.gz
  dep db:push staging -o src=./.db/prod.sql.gz -o backup=false
DESC
);

task('db:push', function () {
    $hostAlias = currentHost()->getAlias();
    if ($hostAlias === 'production') {
        throw new GracefulShutdownException('[db:push] Refusing to overwrite the production database.');
    }

    $src = trim((string) get('src', ''));
    if ($src === '' || !testLocally('[ -f ' . escapeshellarg($src) . ' ]')) {
        throw new GracefulShutdownException("[db:push] Local dump not found: '{$src}' (set -o src=...)");
    }

    $ts         = date('Ymd-His');
    $dumpRemote = "/tmp/db-push-{$hostAlias}-{$ts}.sql.gz";
    $backupDir  = '{{deploy_path}}/backups';
    $backup     = filter_var(get('backup', true), FILTER_VALIDATE_BOOLEAN);
    $backupKeep = max(1, (int) get('backup_keep', 3));

    if ($backup) {
        $backupFile = "{$backupDir}/db-{$hostAlias}-{$ts}.sql.gz";
        writeln("<info>[db:push] Backing up {$hostAlias} database to {$backupFile}...</info>");
        run('mkdir -p ' . $backupDir);
        run('{{bin/n98}} db:dump --no-tablespaces --compression=gzip ' . escapeshellarg($backupFile));
        run("ls -1t {$backupDir}/db-{$hostAlias}-*.sql.gz | tail -n +" . ($backupKeep + 1) . ' | xargs -r rm -f');
    } else {
        writeln('<comment>[db:push] Skipping backup.</comment>');
    }

    writeln('<info>[db:push] Uploading dump...</info>');
    upload($src, $dumpRemote);

    invoke('magento:maintenance:enable');
    try {
        writeln('<info>[db:push] Importing dump...</info>');
        run('{{bin/n98}} db:import --compression=gzip --drop-tables ' . escapeshellarg($dumpRemote), ['timeout' => null]);

        invoke('config:data:import');
        run('{{bin/n98}} db:query "TRUNCATE TABLE cron_schedule"');
        run('{{bin/php}} {{bin/magento}} setup:upgrade --keep-generated --no-interaction', ['timeout' => null]);
        run('{{bin/php}} {{bin/magento}} cache:flush');
    } finally {
        run('rm -f ' . escapeshellarg($dumpRemote));
        invoke('magento:maintenance:disable');
    }

    writeln('<info>[db:push] Database import complete.</info>');
});

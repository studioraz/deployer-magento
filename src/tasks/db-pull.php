<?php

namespace SR\Deployer;

use function Deployer\desc;
use function Deployer\task;
use function Deployer\run;
use function Deployer\runLocally;
use function Deployer\download;
use function Deployer\writeln;
use function Deployer\get;
use function Deployer\currentHost;

desc(<<<'DESC'
Sync database from a chosen remote environment to local using n98-magerun2 and ddev.

Usage:
  dep db:pull <environment> [options]

This task will:
  • Dump the remote database (gzip compressed) using n98-magerun2.
  • Download the dump file to the local machine.
  • Optionally import the dump into your local DDEV database.
  • Clean up temporary dump files (remote always, local only if imported).

Variables:
  import (bool)  Whether to import after download. Default: true.
  full   (bool)  If true, do NOT strip anything (full DB). Default: false.
  strip  (string)Value for --strip. Space-separated groups/tables.
          If not provided, defaults to "@trade @log".

Examples:
  # Full sync (strip @trade and @log by default)
  dep db:pull production

  # Full database dump (no stripping)
  dep db:pull production -o full=true

  # Custom strip set (multiple groups/tables)
  dep db:pull staging -o strip='@development @customers'

  # Download only (no import)
  dep db:pull staging -o import=false
DESC
);

task('db:pull', function () {
    $ts         = date('Ymd-His');
    $hostAlias  = currentHost()->getAlias();
    $dumpRemote = "/tmp/db-{$hostAlias}-{$ts}.sql.gz";
    $dumpLocal  = "./.db/backups/db-{$hostAlias}-{$ts}.sql.gz";

    $shouldImport = filter_var(get('import', true), FILTER_VALIDATE_BOOLEAN);
    $fullDump     = filter_var(get('full', false), FILTER_VALIDATE_BOOLEAN);
    $stripInput   = trim((string) get('strip', ''));

    // Fallback: if no strip and not full, use @trade @log
    if (!$fullDump && $stripInput === '') {
        $stripInput = '@trade @log';
    }

    // Build base command
    $baseCmd = "{{bin/n98}} db:dump --no-tablespaces --compression=gzip";

    // Add --strip only if not full
    $dumpCmd = $fullDump
        ? sprintf("%s %s", $baseCmd, escapeshellarg($dumpRemote))
        : sprintf("%s --strip=%s %s", $baseCmd, escapeshellarg($stripInput), escapeshellarg($dumpRemote));

    writeln("<info>[db:pull] Creating remote dump via n98...</info>");
    writeln("<comment>[db:pull] Dump command: {$dumpCmd}</comment>");
    run('mkdir -p ' . escapeshellarg(dirname($dumpRemote)));
    run($dumpCmd);

    writeln("<info>[db:pull] Downloading dump...</info>");
    runLocally('mkdir -p ' . escapeshellarg(dirname($dumpLocal)));
    download($dumpRemote, $dumpLocal);

    writeln("<info>[db:pull] Cleaning up remote dump...</info>");
    run('rm -f ' . escapeshellarg($dumpRemote));

    if ($shouldImport) {
        writeln("<info>[db:pull] Importing into local via ddev...</info>");
        runLocally("ddev import-db --src=" . escapeshellarg($dumpLocal));
        writeln("<info>[db:pull] Cleaning up local dump...</info>");
        runLocally('rm -f ' . escapeshellarg($dumpLocal));
    } else {
        writeln("<comment>[db:pull] Skipping import (download only). Keeping local dump: {$dumpLocal}</comment>");
    }

    writeln('<info>[db:pull] Database sync complete.</info>');
});

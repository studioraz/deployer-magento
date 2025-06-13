<?php

namespace SR\Deployer;

use function Deployer\desc;
use function Deployer\run;
use function Deployer\task;
use function Deployer\after;
use function Deployer\test;
use function Deployer\currentHost;
desc('Import custom config from JSON files');
task('config:data:import', function () {
    if (test('[ -d {{config_store_path}} ]')) {
        run('{{bin/php}} {{bin/magento}} config:data:import {{config_store_path}} '
            . currentHost()->getAlias() . ' --no-cache');
    } else {
        writeln('<info>config/store folder not found – skipping config:data:import.</info>');
    }
});
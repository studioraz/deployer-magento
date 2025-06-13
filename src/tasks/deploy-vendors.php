<?php

namespace SR\Deployer;

use function Deployer\commandExist;
use function Deployer\desc;
use function Deployer\run;
use function Deployer\task;
use function Deployer\warning;

desc('Installs vendors');
task('deploy:vendors', function () {
    if (!commandExist('unzip')) {
        warning('To speed up composer installation setup "unzip" command with PHP zip extension.');
    }
    run('cd {{release_or_current_path}}/{{magento_dir}} && {{bin/composer}} {{composer_action}} {{composer_options}} 2>&1');
});
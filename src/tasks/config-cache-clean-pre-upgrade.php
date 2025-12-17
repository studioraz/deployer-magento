<?php

namespace SR\Deployer;

use function Deployer\commandExist;
use function Deployer\desc;
use function Deployer\run;
use function Deployer\task;
use function Deployer\get;
use function Deployer\warning;

desc('Config cache clean pre-upgrade ');
task('magento:cache:clean:pre_upgrade', function () {
    $releasePath = get('release_path');
    run("cd {$releasePath} && {{bin/magento}} cache:clean config");
});
#!/usr/bin/env bash
set -euo pipefail

# 2) Gather inputs for the GH workflow
echo "==> Configuring Build & Deploy workflow"
read -rp "PHP version to use [8.3]: " PHP_VERSION
PHP_VERSION="${PHP_VERSION:-8.3}"

read -rp "Default environment name [staging]: " DEFAULT_ENV
DEFAULT_ENV="${DEFAULT_ENV:-staging}"

echo "Enter environment options, separated by spaces (first will be default)"
read -rp "Options [staging production]: " ENV_OPTS_RAW
ENV_OPTS_RAW="${ENV_OPTS_RAW:-staging production}"
IFS=' ' read -r -a ENV_OPTS <<<"$ENV_OPTS_RAW"

# 3) Write .github/workflows/build-and-deploy.yml
WORKFLOW_DIR=".github/workflows"
WORKFLOW_FILE="$WORKFLOW_DIR/build-and-deploy.yml"
mkdir -p "$WORKFLOW_DIR"
cat > "$WORKFLOW_FILE" <<EOF
name: Build & Deploy

on:
  workflow_dispatch:
    inputs:
      environment:
        type: choice
        description: Deployment environment
        required: true
        default: "$DEFAULT_ENV"
        options:
$(for opt in "${ENV_OPTS[@]}"; do
  echo "          - $opt"
done)

jobs:
  call-build-and-deploy:
    uses: studioraz/ci-templates/.github/workflows/build-and-deploy.yml@1.0.0
    with:
      environment: \${{ inputs.environment }}
      php-version: "$PHP_VERSION"
      project-dir: "."
    secrets: inherit
EOF

echo "✔ Workflow written to $WORKFLOW_FILE"

# 5) Create package.json in Magento root (only if using Hyvä themes)
read -rp "Is this project using Hyvä themes? [Y/n]: " USE_HYVA
USE_HYVA="${USE_HYVA:-Y}"
if [[ ! "$USE_HYVA" =~ ^[Yy]$ ]]; then
  echo "Skipping package.json creation for Hyvä"
else
echo "==> Creating package.json for Tailwind workspaces"

PKG_JSON_FILE="package.json"
cat > "$PKG_JSON_FILE" <<EOF
{
  "name": "studiroaz-package",
  "private": true,
  "workspaces": [
    "app/design/frontend/SR/hyva-base/web/tailwind",
    "vendor/hyva-themes/magento2-default-theme/web/tailwind"
  ],
  "scripts": {
    "postinstall": "patch-package",
    "build-base": "npm --workspace app/design/frontend/SR/hyva-base/web/tailwind run build-prod",
    "build-all": "npm run build-base"
  },
  "dependencies": {
    "patch-package": "^8.0.0"
  }
}

EOF

echo "✔ package.json written to $PKG_JSON_FILE"
fi

echo "==> Creating patches directory and generating hyva-modules patch"
mkdir -p patches
cat > "patches/@hyva-themes+hyva-modules+1.0.11.patch" <<'EOF'
diff --git a/src/index.js b/src/index.js
index OLDHASH..NEWHASH 100644
--- a/src/index.js
+++ b/src/index.js
@@ -1,5 +1,9 @@
 // Global variable with prefix to use inside extensions tailwind.config.js file to require installed node modules.
 // Usage example: const colors = require(`${themeDirRequire}/tailwindcss/colors`);
-global.themeDirRequire = `${tailwindDir}/node_modules`
+// Ensure Tailwind plugins resolve from the Magento root node_modules
+global.themeDirRequire = basePath
+  ? path.join(basePath, 'node_modules')
+  : `${tailwindDir}/node_modules`;
+
+// (previous default kept for fallback)
+// global.themeDirRequire = `${tailwindDir}/node_modules`

 /**
  * Set the purge content as absolute paths on configClone in targetVersion structure
EOF
echo "✔ Generated patches/@hyva-themes+hyva-modules+1.0.11.patch"

# 7) Create a basic deploy.php template in the Magento root
echo "==> Generating a basic deploy.php template in deploy.php"
cat > "deploy.php" <<'EOF'
<?php
namespace Deployer;

use Deployer\Exception\GracefulShutdownException;

require __DIR__ . '/vendor/autoload.php';

use SR\Deployer\RecipeLoader;

RecipeLoader::load();

localhost()
    ->set('local', true);

// **************************** Hosts **************************/

// copy from [repo-root]/deployer/deployer.php

// *************************** General Configuration **************************/

// copy your hosts from [repo-root]/deployer/deployer.php

set('application', '');
set('repository', '');

// *************************** Static Content **************************/

// copy your settings from [repo-root]/deployer/deployer.php

set('magento_themes', []);

// **************************** Slack Notifications ****************************/

// copy your settings from [repo-root]/deployer/deployer.php
set('slack_webhook', '');
set('slack_channel', '');
EOF

echo "✔ Basic deploy.php template created at deploy.php. Please customize it using settings from [repo-root]/deployer/deployer.php."

echo "modify composer.json"
COMPOSER_JSON="composer.json"

# 4) Install deployer-magento2 in Magento root

ddev composer require studioraz/deployer-magento --no-update
echo "Updating lock file"
ddev composer update --lock --no-interaction

echo "Updating allow-plugins in $COMPOSER_JSON"
# Insert new plugin entries after magento/* line
sed -i '/"magento\/\*": true,/ a\
          "magento/composer-dependency-version-audit-plugin": false,\
          "magento/composer-root-update-plugin": false,\
          "magento/inventory-composer-installer": false,\
          "magento/magento-composer-installer": true' "$COMPOSER_JSON"
echo "✔ Plugin entries inserted after magento/* in $COMPOSER_JSON"

echo "==> Installing studioraz/deployer-magento2"
composer require studioraz/deployer-magento --no-update
echo "Updating lock file"
composer update --lock --no-interaction


echo "🎉 Setup complete! Next steps:"
echo "  • Review and commit $WORKFLOW_FILE"
echo "  • Run npm install && npm run build-all"
echo "  • Commit and push your changes"

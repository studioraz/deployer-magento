#!/usr/bin/env bash
set -euo pipefail

# 1) Ask for Magento root
read -rp "Enter your Magento root directory (relative to this folder) [src]: " MAGENTO_ROOT
MAGENTO_ROOT="${MAGENTO_ROOT:-src}"

# 2) Gather inputs for the GH workflow
echo "==> Configuring Build & Deploy workflow"
read -rp "PHP version to use [8.3]: " PHP_VERSION
PHP_VERSION="${PHP_VERSION:-8.3}"

read -rp "Project directory under repo [${MAGENTO_ROOT}]: " PROJECT_DIR
PROJECT_DIR="${PROJECT_DIR:-$MAGENTO_ROOT}"

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
      php-version:
        description: PHP version to use
        required: true
        default: "$PHP_VERSION"
      project-dir:
        description: Path to Magento root
        required: true
        default: "$PROJECT_DIR"

jobs:
  call-build-and-deploy:
    uses: studioraz/ci-templates/.github/workflows/build-and-deploy.yml@v1
    with:
      environment: \${{ inputs.environment }}
      php-version: \${{ inputs.php-version }}
      project-dir: \${{ inputs.project-dir }}
    secrets: inherit
EOF

echo "✔ Workflow written to $WORKFLOW_FILE"

# 4) Install deployer-magento2 in Magento root
echo "==> Installing studioraz/deployer-magento2 via Composer in $MAGENTO_ROOT"
cd "$MAGENTO_ROOT"
if [ ! -f composer.json ]; then
  echo "No composer.json found in $MAGENTO_ROOT"
  exit 1
fi
composer require studioraz/deployer-magento2 --no-update
echo "Updating lock file"
composer update --lock --no-interaction

# 5) Create package.json in Magento root (only if using Hyvä themes)
read -rp "Is this project using Hyvä themes? [Y/n]: " USE_HYVA
USE_HYVA="${USE_HYVA:-Y}"
if [[ ! "$USE_HYVA" =~ ^[Yy]$ ]]; then
  echo "Skipping package.json creation for Hyvä"
else
echo "==> Creating package.json for Tailwind workspaces in $MAGENTO_ROOT"
read -rp "Theme namespace/vendor (e.g. StudioRaz/MyTheme) [StudioRaz/MyTheme]: " THEME_NS
THEME_NS="${THEME_NS:-StudioRaz/MyTheme}"

PKG_JSON_FILE="package.json"
cat > "$PKG_JSON_FILE" <<EOF
{
  "name": "studioraz/hyva-themes",
  "private": true,
  "workspaces": [
    "app/design/frontend/$THEME_NS/web/tailwind"
  ],
  "scripts": {
    "build:child": "npm --workspace app/design/frontend/$THEME_NS/web/tailwind run build-prod",
    "build:all": "npm run build:child"
  }
}
EOF

echo "✔ package.json written to $MAGENTO_ROOT/$PKG_JSON_FILE"
fi

# 6) Copy Deployer configuration into project root
echo "==> Copying Deployer configuration into project root"
cp -r vendor/studioraz/deployer-magento2/deployer .
echo "✔ Deployer config copied to $(pwd)/deployer"

# 7) Download deploy.php from remote repository
read -rp "Download deploy.php entrypoint into $PROJECT_DIR/deploy.php? [Y/n]: " DL_DEPLOY
DL_DEPLOY="${DL_DEPLOY:-Y}"
if [[ "$DL_DEPLOY" =~ ^[Yy]$ ]]; then
  DEST="$PROJECT_DIR/deploy.php"
  echo "Downloading deploy.php to $DEST"
  curl -sSL "https://raw.githubusercontent.com/studioraz/project-hyva/refs/heads/master/src/deploy.php?token=GHSAT0AAAAAADFEEEZK3IMS45CBKISKR7DA2CR3VDA" -o "$DEST"
  if [ $? -ne 0 ]; then
    echo "Error: Failed to download deploy.php"
    exit 1
  fi
  echo "✔ deploy.php downloaded to $DEST"
  echo "Please review and customize $DEST by copying project settings from [repo_root]/deployer/deployer.php"
else
  echo "Skipped downloading deploy.php"
fi

# 8) Update src/composer.json allow-plugins section
COMPOSER_JSON="${MAGENTO_ROOT}/composer.json"
if [ ! -f "$COMPOSER_JSON" ]; then
  echo "Warning: $COMPOSER_JSON not found, skipping plugin adjustments."
else
  echo "Updating allow-plugins in $COMPOSER_JSON"
  # Insert new plugin entries after magento/* line
  sed -i '/"magento\/\*": true,/ a\
            "magento/composer-dependency-version-audit-plugin": false,\
            "magento/composer-root-update-plugin": false,\
            "magento/inventory-composer-installer": false,\
            "magento/magento-composer-installer": true' "$COMPOSER_JSON"
  echo "✔ Plugin entries inserted after magento/* in $COMPOSER_JSON"
fi

echo "🎉 Setup complete! Next steps:"
echo "  • Review and commit $WORKFLOW_FILE"
echo "  • cd $MAGENTO_ROOT && npm ci && npm run build:all"
echo "  • Commit and push your changes"

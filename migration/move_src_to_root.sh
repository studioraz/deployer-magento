#!/usr/bin/env bash
# move_src_to_root.sh
# A history-preserving script to:
#  - move tracked files under src/ into the repository root
#  - move all visible files and directories (including dotfiles) from src/
#  - remove dev/ folder from Git tracking and ignore it
#  - merge or move src/.gitignore appropriately

# Merge src/.gitignore into root .gitignore (only once)
if [[ -f src/.gitignore ]]; then
  echo "Merging src/.gitignore into root .gitignore"
  touch .gitignore
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    if ! grep -qxF "$line" .gitignore; then
      echo "$line" >> .gitignore
    fi
  done < src/.gitignore
  git add .gitignore

  # Remove src/.gitignore from git if tracked, else just delete
  if git ls-files --error-unmatch src/.gitignore >/dev/null 2>&1; then
    git rm --cached src/.gitignore
  fi
  if [[ -f src/.gitignore ]]; then
    rm -f src/.gitignore
  fi
fi

set -euo pipefail
# Enable dotfile globbing and skip non-matches
shopt -s dotglob nullglob

# 3. Move only directories and composer.json/auth.json from src/ to root
for item in src/* src/.*; do
  name="${item#src/}"
  # skip . and .. and .git
  [[ "$name" == "." || "$name" == ".." || "$name" == ".git" ]] && continue

  if [[ -d "$item" ]]; then
    if [[ ! -e "$name" ]]; then
      echo "Moving directory $item -> $name"
      git mv "$item" "$name" 2>/dev/null || mv "$item" "$name"
    else
      echo "Skipping existing directory: $name"
    fi
  elif [[ -f "$item" ]]; then
    if [[ "$name" == "composer.json" || "$name" == "auth.json" ]]; then
      if [[ ! -e "$name" ]]; then
        echo "Moving file $item -> $name"
        git mv "$item" "$name" 2>/dev/null || mv "$item" "$name"
      else
        echo "Skipping existing file: $name"
      fi
    else
      echo "Skipping file: $name"
    fi
  fi
done

# 4. ignore dev/tests folder
if [[ -d dev ]]; then
  echo "Ignore dev/tests folder"
  touch .gitignore
  if ! grep -qxF "dev/tests" .gitignore; then
    echo "Adding 'dev/tests' to .gitignore"
    echo "dev/tests" >> .gitignore
    git add .gitignore
  fi
fi

# 6. Force remove src/ if it still exists
if [[ -d src ]]; then
  echo "Force removing src/ directory"
  rm -rf src
fi

# 7. Update DDEV docroot to repository root using DDEV CLI
if command -v ddev >/dev/null 2>&1; then
  echo "Reconfiguring DDEV docroot to repository root"
  ddev config \
    --docroot=pub \
    --web-working-dir=/var/www/html \
    --composer-root=.
  echo "Restarting DDEV to apply changes"
  ddev restart
fi

# 9. Final reminder
echo
echo "Done. Review with 'git status' and commit with:"
echo "  git commit -m 'Move all content from src/ to root, remove dev/, update .gitignore, update composer.json Magento flags'"

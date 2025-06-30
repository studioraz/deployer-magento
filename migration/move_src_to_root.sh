#!/usr/bin/env bash
# move_src_to_root.sh
# A history-preserving script to:
#  - move tracked files under src/ into the repository root
#  - move all visible files and directories (including dotfiles) from src/
#  - remove dev/ folder from Git tracking and ignore it
#  - merge or move src/.gitignore appropriately

# Merge src/.gitignore into root .gitignore
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
  git rm --cached src/.gitignore
fi

set -euo pipefail
# Enable dotfile globbing and skip non-matches
shopt -s dotglob nullglob

# 1. Handle src/.gitignore (move or merge into root .gitignore)
if [[ -f src/.gitignore ]]; then
  if [[ ! -e ".gitignore" ]]; then
    echo "Moving src/.gitignore to root .gitignore"
    git mv src/.gitignore .gitignore
  else
    echo "Merging src/.gitignore entries into root .gitignore"
    while IFS= read -r line; do
      [[ -z "$line" ]] && continue
      if ! grep -qxF "$line" .gitignore; then
        echo "$line" >> .gitignore
      fi
    done < src/.gitignore
    git add .gitignore
    git rm --cached src/.gitignore
  fi
fi


# 3. Move all items from src/ (visible and hidden) into root
for item in src/* src/.*; do
  name="${item#src/}"
  # skip . and .. and .git
  [[ "$name" == "." || "$name" == ".." || "$name" == ".git" ]] && continue
  if [[ ! -e "$name" ]]; then
    echo "Moving $item -> $name"
    git mv "$item" "$name" 2>/dev/null || mv "$item" "$name"
  else
    echo "Skipping existing item: $name"
  fi
done

# 4. Remove dev/ folder from Git tracking and ignore it
if [[ -d dev ]]; then
  echo "Removing dev/tests folder from Git tracking"
  git rm -r --cached dev/tests
  touch .gitignore
  if ! grep -qxF "dev/" .gitignore; then
    echo "Adding 'dev/' to .gitignore"
    echo "dev/tests" >> .gitignore
    git add .gitignore
  fi
fi

# 5. Remove src/ directory if now empty
if [[ -d src ]]; then
  if [[ -z "$(ls -A src)" ]]; then
    echo "Removing empty src/ directory"
    rmdir src
  else
    echo "src/ still contains files; not removed. Contents:"
    ls -A src
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

# 8. Final reminder
echo
echo "Done. Review with 'git status' and commit with:"
echo "  git commit -m 'Move all content from src/ to root, remove dev/, update .gitignore, skip conflicts'"

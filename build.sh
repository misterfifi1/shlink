#!/usr/bin/env bash
set -e

if [[ "$#" -ne 1 ]]; then
  echo "Usage:" >&2
  echo "   $0 {version}" >&2
  exit 1
fi

version=$1
builtcontent="./build/shlink_${version}_dist"
projectdir=$(pwd)
[[ -f ./composer.phar ]] && composerBin='./composer.phar' || composerBin='composer'

# Copy project content to temp dir
echo 'Copying project files...'
rm -rf "${builtcontent}"
mkdir -p "${builtcontent}"
rsync -av * "${builtcontent}" \
    --exclude=bin/test \
    --exclude=data/infra \
    --exclude=data/travis \
    --exclude=data/migrations_template.txt \
    --exclude=data/GeoLite2-City.mmdb \
    --exclude=**/.gitignore \
    --exclude=CHANGELOG.md \
    --exclude=composer.lock \
    --exclude=vendor \
    --exclude=docs \
    --exclude=indocker \
    --exclude=docker* \
    --exclude=php* \
    --exclude=infection.json \
    --exclude=phpstan.neon \
    --exclude=config/autoload/*local* \
    --exclude=config/test \
    --exclude=**/test* \
    --exclude=build* \
    --exclude=.github
cd "${builtcontent}"

# Install dependencies
echo "Installing dependencies with $composerBin..."
${composerBin} self-update
${composerBin} install --no-dev --optimize-autoloader --apcu-autoloader --no-progress --no-interaction

# Delete development files
echo 'Deleting dev files...'
rm composer.*
rm -f data/database.sqlite

# Update shlink version in config
sed -i "s/%SHLINK_VERSION%/${version}/g" config/autoload/app_options.global.php

# Compressing file
echo 'Compressing files...'
cd "${projectdir}"/build
rm -f ./shlink_${version}_dist.zip
zip -ry ./shlink_${version}_dist.zip ./shlink_${version}_dist
cd "${projectdir}"
rm -rf "${builtcontent}"

echo 'Done!'

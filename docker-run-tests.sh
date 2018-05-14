﻿#!/usr/bin/env bash

set -e

if [[ -z "${APIGEE_EDGE_ENDPOINT}" ]] || [[ -z "${APIGEE_EDGE_USERNAME}" ]] || [[ -z "${APIGEE_EDGE_PASSWORD}" ]] || [[ -z "${APIGEE_EDGE_ORGANIZATION}" ]]; then
  echo "Incomplete configuration. Please make sure the following environment variables exist and not empty: APIGEE_EDGE_ENDPOINT, APIGEE_EDGE_USERNAME, APIGEE_EDGE_PASSWORD, APIGEE_EDGE_ORGANIZATION."
  exit 1
fi

MODULE_PATH="/opt/drupal-module"
WEB_ROOT="/var/www/html/build"
WEB_ROOT_PARENT="/var/www/html"
COMPOSER_GLOBAL_OPTIONS="--no-interaction --prefer-dist -o"

# We mounted the cache/files folder from the host so we have to fix permissions
# on the parent cache folder because it did not exist before.
sudo -u root sh -c "chown -R wodby:wodby /home/wodby/.composer/cache"

# Copy module's source to web root parent.
cp -R ${MODULE_PATH}/. ${WEB_ROOT_PARENT} && cd ${WEB_ROOT_PARENT}

# Install module with its dependencies (including dev dependencies).
composer update ${COMPOSER_GLOBAL_OPTIONS} ${DEPENDENCIES} --with-dependencies

# Allow to run tests with a specific Drupal core version (ex.: latest dev).
if [ -n "${DRUPAL_CORE}" ]; then
  composer require drupal/core:${DRUPAL_CORE} webflo/drupal-core-require-dev:${DRUPAL_CORE} ${COMPOSER_GLOBAL_OPTIONS};
fi

# Symlink module to the contrib folder.
ln -s ${MODULE_PATH} ${WEB_ROOT}/modules/contrib/${DRUPAL_MODULE_NAME}

# Based on https://www.drupal.org/node/244924.
# Also fix permissions on directory and .htaccess file.
sudo -u root sh -c "chown -R wodby:www-data $WEB_ROOT \
    && find $WEB_ROOT -type d -exec chmod 6750 '{}' \; \
    && find $WEB_ROOT -type f -exec chmod 0640 '{}' \; \
    && chmod 755 $WEB_ROOT \
    && chmod 644 $WEB_ROOT/.htaccess"

sudo -u root sh -c "mkdir -p $WEB_ROOT/sites/default/files \
    && chown -R wodby:www-data $WEB_ROOT/sites/default/files \
    && chmod 6770 $WEB_ROOT/sites/default/files"

# Pre-create simpletest directory...
sudo -u root mkdir -p ${WEB_ROOT}/sites/simpletest
# and another required libraries.
# (These are required by core/phpunit.xml.dist).
sudo -u root mkdir -p ${WEB_ROOT}/profiles
sudo -u root mkdir -p ${WEB_ROOT}/themes

# Make sure that the log folder is writable for both www-data and wodby users.
# Also create a dedicated folder for PHPUnit outputs.
sudo -u root sh -c "chown -R www-data:wodby /mnt/files/log \
 && chmod -R 6750 /mnt/files/log \
 && mkdir -p /mnt/files/log/simpletest/browser_output \
 && chown -R www-data:wodby /mnt/files/log/simpletest \
 && chmod -R 6750 /mnt/files/log/simpletest"

# Change location of the browser_output folder, because it seems even if
# BROWSERTEST_OUTPUT_DIRECTORY is set the html output is printed out to
# https://github.com/drupal/core/blob/8.5.0/tests/Drupal/Tests/BrowserTestBase.php#L1086
sudo -u root ln -s /mnt/files/log/simpletest/browser_output ${WEB_ROOT}/sites/simpletest/browser_output

# Fix permissions on on simpletest and its sub-folders.
sudo -u root sh -c "chown -R www-data:wodby $WEB_ROOT/sites/simpletest \
    && chmod -R 6750 $WEB_ROOT/sites/simpletest"

# Let's see installed dependencies.
composer show

# Download the test runner.
curl -L -o /var/www/html/testrunner https://github.com/Pronovix/testrunner/releases/download/v0.4/testrunner-linux-amd64
chmod +x /var/www/html/testrunner
# Do not exit if any PHPUnit test fails.
set +e
sudo -u root -E sudo -u www-data -E /var/www/html/testrunner -verbose -threads=${THREADS} -root=${WEB_ROOT}/modules/contrib/apigee_edge/tests -command="$WEB_ROOT_PARENT/vendor/bin/phpunit -c $WEB_ROOT/core -v --debug --printer \Drupal\Tests\Listeners\HtmlOutputPrinter"

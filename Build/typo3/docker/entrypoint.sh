#!/bin/bash
set -eu

runuser -u www-data vendor/bin/typo3 install:extensionsetupifpossible
vendor/bin/typo3 backend:createadmin admin password || true

# create the lock directory upfront, concurrent first requests would race for its creation otherwise
runuser -u www-data -- mkdir -p var/lock

exec apache2-foreground

#!/usr/bin/env bash
# Reload server BE sesudah mengubah PHP atau .env di BE_DIR - RoadRunner maupun php-fpm (lihat lib/env.sh be_reload).
#   scripts/be-reload.sh
source "$(dirname "$0")/lib/env.sh" || exit $?
be_reload

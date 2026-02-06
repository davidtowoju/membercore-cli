#!/usr/bin/env bash
set -euo pipefail

log() { printf "\n%s\n" "$1"; }

# Optional runner: runs command, if it fails we continue with a message
optional() {
  local name="$1"; shift
  "$@" >/dev/null 2>&1 || log "$name skipped"
}

# Resolve the plugin directory (where this script lives)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# Install Composer dependencies if vendor directory is missing
if [ ! -d "$PLUGIN_DIR/vendor" ]; then
  log "Installing Composer dependencies"
  if command -v composer >/dev/null 2>&1; then
    composer install --working-dir="$PLUGIN_DIR" --no-dev --no-interaction
  elif [ -f "$PLUGIN_DIR/composer.phar" ]; then
    php "$PLUGIN_DIR/composer.phar" install --working-dir="$PLUGIN_DIR" --no-dev --no-interaction
  else
    log "WARNING: Composer not found and vendor/ is missing. Commands that require Faker will fail."
  fi
fi

# Require WP installed
wp core is-installed >/dev/null 2>&1 || {
  echo "WordPress is not installed here. Aborting."
  exit 1
}

# Auto detect from existing WP
SITE_URL="$(wp option get siteurl)"
SITE_TITLE="$(wp option get blogname)"

# Flexible settings (override by env vars)
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-pass}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.test}"

FIRST_NAME="${FIRST_NAME:-Deji}"
LAST_NAME="${LAST_NAME:-Towoju}"

PLUGINS_DEFAULT="metabase-post-user-meta-editor spatie-ray user-switching code-snippets membercore membercore-cli membercore-directory"
PLUGINS="${PLUGINS:-$PLUGINS_DEFAULT}"

MEMBERSHIPS="${MEMBERSHIPS:-9,10,11,12}"
USER_COUNT="${USER_COUNT:-20}"
MEMBERSHIP_PROBABILITY="${MEMBERSHIP_PROBABILITY:-75}"

# Switches (set to 1 to skip)
SKIP_DB_RESET="${SKIP_DB_RESET:-0}"
SKIP_PLUGIN_ACTIVATE="${SKIP_PLUGIN_ACTIVATE:-0}"
SKIP_ADMIN_NAME="${SKIP_ADMIN_NAME:-0}"
SKIP_SNIPPET="${SKIP_SNIPPET:-0}"
SKIP_PRODUCTS="${SKIP_PRODUCTS:-0}"
SKIP_USERS="${SKIP_USERS:-0}"
SKIP_STRIPE="${SKIP_STRIPE:-0}"
SKIP_CONNECT_COACHKIT="${SKIP_CONNECT_COACHKIT:-0}"

log "Using site: $SITE_TITLE ($SITE_URL)"

if [ "$SKIP_DB_RESET" -eq 0 ]; then
  log "Resetting database"
  wp db reset --yes

  log "Reinstalling WordPress"
  wp core install \
    --url="$SITE_URL" \
    --title="$SITE_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
else
  log "DB reset skipped"
fi

if [ "$SKIP_PLUGIN_ACTIVATE" -eq 0 ]; then
  log "Activating plugins"
  # shellcheck disable=SC2086
  wp plugin activate $PLUGINS
else
  log "Plugin activation skipped"
fi

if [ "$SKIP_ADMIN_NAME" -eq 0 ]; then
  log "Setting admin name"
  ADMIN_ID="$(wp user list --field=ID --role=administrator | head -n 1)"
  wp user meta update "$ADMIN_ID" first_name "$FIRST_NAME"
  wp user meta update "$ADMIN_ID" last_name "$LAST_NAME"
else
  log "Admin name update skipped"
fi

# Optional Mailtrap snippet (requires membercore-directory plugin)
if [ "$SKIP_SNIPPET" -eq 0 ]; then
  log "Creating Mailtrap snippet (optional)"

  if [ -n "${MAILTRAP_USER:-}" ] && [ -n "${MAILTRAP_PASS:-}" ]; then
    wp mcdir snippet create "Mailtrap Email Configuration" \
      --code="// Mailtrap SMTP for local testing
function mailtrap(\$phpmailer) {
  \$phpmailer->isSMTP();
  \$phpmailer->Host = 'sandbox.smtp.mailtrap.io';
  \$phpmailer->SMTPAuth = true;
  \$phpmailer->Port = 2525;
  \$phpmailer->Username = '${MAILTRAP_USER}';
  \$phpmailer->Password = '${MAILTRAP_PASS}';
}
add_action('phpmailer_init', 'mailtrap');" \
      >/dev/null 2>&1 || log "mcdir snippet command not available, skipping"
  else
    log "MAILTRAP_USER or MAILTRAP_PASS not set, skipping snippet"
  fi
else
  log "Snippet creation skipped"
fi

# Products (optional)
if [ "$SKIP_PRODUCTS" -eq 0 ]; then
  log "Creating products"

  wp post create --post_type=membercoreproduct \
    --post_title="Basic Membership" \
    --post_status=publish \
    --meta_input='{"_meco_product_price":"29.00","_meco_product_period_type":"lifetime","_meco_product_period":"1"}'

  wp post create --post_type=membercoreproduct \
    --post_title="Basic Membership – Monthly" \
    --post_status=publish \
    --meta_input='{"_meco_product_price":"9.00","_meco_product_period_type":"months","_meco_product_period":"1"}'

  wp post create --post_type=membercoreproduct \
    --post_title="Pro Membership – Yearly" \
    --post_status=publish \
    --meta_input='{"_meco_product_price":"99.00","_meco_product_period_type":"years","_meco_product_period":"1"}'

  wp post create --post_type=membercoreproduct \
    --post_title="Elite Membership – Monthly" \
    --post_status=publish \
    --meta_input='{"_meco_product_price":"19.00","_meco_product_period_type":"months","_meco_product_period":"1"}'
else
  log "Products skipped"
fi


# Users (optional)
if [ "$SKIP_USERS" -eq 0 ]; then
  log "Creating test users (optional)"
  wp meco user bulk-create-from-json \
    --count="$USER_COUNT" \
    --upload-avatars \
    --skip-existing \
    --memberships="$MEMBERSHIPS" \
    --membership-probability="$MEMBERSHIP_PROBABILITY" \
    --confirm \
    || log "User creation failed (Faker or other dependency issue)"
else
  log "User creation skipped"
fi

# Stripe setup (optional)
if [ "$SKIP_STRIPE" -eq 0 ]; then
  log "Stripe setup (optional)"
  optional "Stripe setup failed" wp meco stripe-setup
else
  log "Stripe setup skipped"
fi

# Connect/CoachKit setup (optional)
if [ "$SKIP_CONNECT_COACHKIT" -eq 0 ]; then
  log "Setting up Connect/CoachKit"
  wp plugin activate membercore-connect
  # wp mcch seed --programs=3 --assign-memberships --memberships-per-program=2
  # wp mcch sync-enrollments
else
  log "Connect/CoachKit setup skipped"
fi

log "Done"

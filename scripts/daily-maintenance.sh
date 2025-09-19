#!/bin/bash

# MemberCore Daily Maintenance Script - Fresh Install Version
# For: directories.today
#
# Usage:
#   ./daily-maintenance.sh [--dry-run] [--verbose]

# Configuration - Auto-detect environment (local vs production)
if [ -d "/home/pluginette-bolbf/directories.today/public" ]; then
    # Production environment
    WORDPRESS_PATH="/home/pluginette-bolbf/directories.today/public"
    LOG_DIR="/home/pluginette-bolbf/directories.today/public/wp-content/uploads/membercore-logs"
    SITE_URL="https://directories.today"
    ADMIN_EMAIL="david@caseproof.com"
elif [ -d "/Users/elizabethtowoju/Sites/caseproof" ]; then
    # Local development environment
    WORDPRESS_PATH="/Users/elizabethtowoju/Sites/caseproof"
    LOG_DIR="/Users/elizabethtowoju/Sites/caseproof/wp-content/uploads/membercore-logs"
    SITE_URL="https://caseproof.test"
    ADMIN_EMAIL="admin@caseproof.test"
else
    echo "Error: Could not detect WordPress installation"
    exit 1
fi
LOG_FILE="$LOG_DIR/daily-maintenance-$(date +%Y-%m-%d).log"
LOCK_FILE="/tmp/membercore-maintenance.lock"

# Parse arguments
DRY_RUN=false
VERBOSE=false
QUIET_FLAG="--quiet"

while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --verbose)
            VERBOSE=true
            QUIET_FLAG=""
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 [--dry-run] [--verbose]"
            exit 1
            ;;
    esac
done

# Functions
log() {
    local message="$(date '+%Y-%m-%d %H:%M:%S') - $1"
    echo "$message"
    
    # Create log directory if it doesn't exist
    mkdir -p "$LOG_DIR"
    echo "$message" >> "$LOG_FILE"
}

log_error() {
    local message="$(date '+%Y-%m-%d %H:%M:%S') - ERROR: $1"
    echo "$message" >&2
    echo "$message" >> "$LOG_FILE"
}

run_wp_command() {
    local description="$1"
    local command="$2"
    local critical="${3:-false}"
    local supports_dry_run="${4:-false}"
    
    log "Starting: $description"
    
    if [ "$VERBOSE" = true ]; then
        log "Command: wp --path=$WORDPRESS_PATH $command"
    fi
    
    # Handle dry-run mode
    if [ "$DRY_RUN" = true ]; then
        if [ "$supports_dry_run" = true ]; then
            command="$command --dry-run"
            log "DRY RUN MODE: $description"
        else
            log "DRY RUN MODE: Would execute - $description"
            return 0
        fi
    fi
    
    # Execute command
    if "$WP_CLI" --path="$WORDPRESS_PATH" $command $QUIET_FLAG; then
        log "✓ Completed: $description"
        return 0
    else
        log_error "✗ Failed: $description"
        if [ "$critical" = true ]; then
            log_error "Critical command failed. Stopping execution."
            exit 1
        fi
        return 1
    fi
}

cleanup() {
    log "Cleaning up..."
    rm -f "$LOCK_FILE"
    
    # Clean up old log files (keep last 30 days)
    find "$LOG_DIR" -name "daily-maintenance-*.log" -type f -mtime +30 -delete 2>/dev/null || true
}

# Trap to ensure cleanup happens
trap cleanup EXIT

# Check if already running
if [ -f "$LOCK_FILE" ]; then
    log_error "Script already running (lock file exists: $LOCK_FILE)"
    exit 1
fi

# Create lock file
echo $$ > "$LOCK_FILE"

# Start maintenance
log "========================================="
log "Starting MemberCore Daily Maintenance"
log "Site: $SITE_URL"
log "Mode: $([ "$DRY_RUN" = true ] && echo 'DRY RUN' || echo 'LIVE')"
log "========================================="

# Debug: Check WordPress installation
if [ ! -f "$WORDPRESS_PATH/wp-config.php" ]; then
    log_error "WordPress not found at $WORDPRESS_PATH"
    log "Note: Database reset will create a new WordPress installation"
    log "Continuing with fresh install process..."
fi

# Check WP-CLI - Try multiple common locations
WP_CLI_PATHS=(
    "/opt/homebrew/bin/wp"
    "/usr/local/bin/wp"
    "/usr/bin/wp"
    "wp"
)

WP_CLI=""
for path in "${WP_CLI_PATHS[@]}"; do
    if command -v "$path" &>/dev/null; then
        WP_CLI="$path"
        break
    fi
done

if [ -z "$WP_CLI" ]; then
    log_error "WP-CLI not found. Checking PATH..."
    log "Current PATH: $PATH"
    log "Looking for wp command..."
    which wp || log "wp command not found"
    log "Please install WP-CLI or run without sudo"
    exit 1
fi

log "Using WP-CLI at: $WP_CLI"

if [ "$VERBOSE" = true ]; then
    log "WordPress path: $WORDPRESS_PATH"
    log "WP-CLI version: $("$WP_CLI" --version 2>/dev/null || echo 'Not found')"
fi

# Initialize counters
TOTAL_COMMANDS=4  # Update this number when adding commands
COMPLETED_COMMANDS=0
FAILED_COMMANDS=0

# Command 1: Database Reset - Complete database reset
# ⚠️  WARNING: This completely resets the database
if run_wp_command "Database Reset" "db reset --yes" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 2: WordPress Fresh Install - Install WordPress with admin user
if run_wp_command "WordPress Fresh Install" "core install --url=$SITE_URL --title=directories --admin_user=admin --admin_password=pass --admin_email=$ADMIN_EMAIL" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 3: Import Database Tables - Import user tables from SQL dump
TABLES_SQL="$WORDPRESS_PATH/wp-content/plugins/membercore-cli/app/assets/tables.sql"
log "Starting: Import Database Tables"
if [ "$DRY_RUN" = true ]; then
    log "DRY RUN MODE: Would import tables.sql"
    ((COMPLETED_COMMANDS++))
else
    if "$WP_CLI" --path="$WORDPRESS_PATH" db import "$TABLES_SQL" $QUIET_FLAG; then
        log "✓ Completed: Import Database Tables"
        ((COMPLETED_COMMANDS++))
    else
        log_error "✗ Failed: Import Database Tables"
        ((FAILED_COMMANDS++))
    fi
fi

# Command 4: Update URLs - Search and replace demo URLs with environment URLs
REPLACE_TO_URL=$(echo "$SITE_URL" | sed 's|https://||')
if run_wp_command "Update Site URLs" "search-replace directories.test $REPLACE_TO_URL --all-tables" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Summary
log "========================================="
log "Maintenance Summary:"
log "Total Commands: $TOTAL_COMMANDS"
log "Completed: $COMPLETED_COMMANDS"
log "Failed: $FAILED_COMMANDS"
log "Success Rate: $(( COMPLETED_COMMANDS * 100 / TOTAL_COMMANDS ))%"

if [ "$DRY_RUN" = true ]; then
    log "DRY RUN COMPLETED - No actual changes made"
fi

# Set exit code based on failures
if [ "$FAILED_COMMANDS" -gt 0 ]; then
    log_error "Maintenance completed with $FAILED_COMMANDS failures"
    exit 1
else
    log "✓ All maintenance tasks completed successfully"
    exit 0
fi

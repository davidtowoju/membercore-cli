#!/bin/bash

# MemberCore Daily Maintenance Script - Fresh Install Version
# For: directories.today
#
# Usage:
#   ./daily-maintenance.sh [--dry-run] [--verbose]

# Configuration for directories.today
WORDPRESS_PATH="/home/pluginette-bolbf/directories.today/public"
LOG_DIR="/home/pluginette-bolbf/directories.today/public/wp-content/uploads/membercore-logs"
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
    
    log "Starting: $description"
    
    if [ "$VERBOSE" = true ]; then
        log "Command: wp --path=$WORDPRESS_PATH $command"
    fi
    
    # Add dry-run flag if specified
    if [ "$DRY_RUN" = true ]; then
        command="$command --dry-run"
        log "DRY RUN MODE: $description"
    fi
    
    # Execute command
    if wp --path="$WORDPRESS_PATH" $command $QUIET_FLAG; then
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
log "Site: directories (directories.today)"
log "Mode: $([ "$DRY_RUN" = true ] && echo 'DRY RUN' || echo 'LIVE')"
log "========================================="

# Debug: Check WordPress installation
if [ ! -f "$WORDPRESS_PATH/wp-config.php" ]; then
    log_error "WordPress not found at $WORDPRESS_PATH"
    log "Note: Database reset will create a new WordPress installation"
    log "Continuing with fresh install process..."
fi

# Check WP-CLI
if ! wp --version &>/dev/null; then
    log_error "WP-CLI not found. Checking PATH..."
    log "Current PATH: $PATH"
    log "Looking for wp command..."
    which wp || log "wp command not found"
    exit 1
fi

if [ "$VERBOSE" = true ]; then
    log "WordPress path: $WORDPRESS_PATH"
    log "WP-CLI version: $(wp --version 2>/dev/null || echo 'Not found')"
fi

# Initialize counters
TOTAL_COMMANDS=11  # Update this number when adding commands
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
if run_wp_command "WordPress Fresh Install" "core install --url=\"https://directories.test\" --title=\"directories\" --admin_user=\"admin\" --admin_password=\"pass\" --admin_email=\"webprofdave@gmail.com\"" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 3: Set Required Options - Set MCPD options to prevent setup wizard
if run_wp_command "Set MCPD Options" "option update mcpd_community_profile_type_added true" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 4: Set Community Directory Option
if run_wp_command "Set Community Directory Option" "option update mcpd_community_directory_added true" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 5: Activate Theme - Activate Frost theme
if run_wp_command "Activate Frost Theme" "theme activate frost" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 6: Activate Plugins - Activate required plugins
if run_wp_command "Activate Plugins" "plugin activate wordpress-importer membercore membercore-cli membercore-profiles-and-directories code-snippets" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 7: Clear Posts Table - Truncate posts for clean import
if run_wp_command "Clear Posts Table" "db query \"TRUNCATE TABLE wp_posts;\"" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 8: Import Demo Data - Import directories and demo content
if run_wp_command "Import Demo Data" "import app/assets/directories.xml --authors=create" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 9: Update URLs - Search and replace demo URLs with live URLs
if run_wp_command "Update Site URLs" "search-replace 'directories.test' 'directories.test'" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 10: Create Demo Users - Bulk create users from JSON with avatars and memberships
if run_wp_command "Create Demo Users" "meco user bulk-create-from-json --upload-avatars --skip-existing --memberships=14,15,16,17 --membership-probability=75 --randomize --confirm" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 11: Sync Directories - Trigger directory sync action
if run_wp_command "Sync Directories" "eval 'do_action(\"mcpd_sync_directories\");'" false; then
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

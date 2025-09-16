#!/bin/bash

# MemberCore Daily Maintenance Script - Production Version
# For: directories.today
#
# Usage:
#   ./daily-maintenance.sh [--dry-run] [--verbose]

# Configuration for directories.today
WORDPRESS_PATH="/home/pluginette-bolbf/directories.today"
LOG_DIR="/home/pluginette-bolbf/directories.today/wp-content/uploads/membercore-logs"
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

run_user_cleanup() {
    local description="$1"
    local critical="${2:-false}"
    
    log "Starting: $description"
    
    # Get list of users to delete (exclude admin user ID 1)
    local users_to_delete
    users_to_delete=$(wp --path="$WORDPRESS_PATH" user list --field=ID --exclude=1 2>/dev/null)
    
    # Debug logging
    local total_users=$(wp --path="$WORDPRESS_PATH" user list --format=count 2>/dev/null)
    log "Debug: Total users found: $total_users"
    log "Debug: Users to delete: '$users_to_delete'"
    
    if [ -z "$users_to_delete" ]; then
        log "No users to delete (only admin user exists)"
        return 0
    fi
    
    local user_count=$(echo "$users_to_delete" | wc -w)
    log "Found $user_count users to delete (excluding admin ID=1)"
    
    if [ "$DRY_RUN" = true ]; then
        log "DRY RUN MODE: Would delete the following users:"
        for user_id in $users_to_delete; do
            local user_info=$(wp --path="$WORDPRESS_PATH" user get "$user_id" --field=user_login 2>/dev/null || echo "ID:$user_id")
            log "  - Would delete user: $user_info (ID: $user_id)"
        done
        log "DRY RUN: Would reassign all content to admin user (ID: 1)"
        return 0
    fi
    
    # WARNING: This is destructive - log it clearly
    log "⚠️  WARNING: Deleting $user_count users and reassigning content to admin (ID: 1)"
    
    # Execute the deletion
    if wp --path="$WORDPRESS_PATH" user delete $users_to_delete --reassign=1 --yes $QUIET_FLAG; then
        log "✓ Completed: $description - Deleted $user_count users"
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
log "Site: directories.today"
log "Mode: $([ "$DRY_RUN" = true ] && echo 'DRY RUN' || echo 'LIVE')"
log "========================================="

# Initialize counters
TOTAL_COMMANDS=1  # Update this number when adding commands
COMPLETED_COMMANDS=0
FAILED_COMMANDS=0

# Command 1: User cleanup - DELETE ALL USERS EXCEPT ADMIN (ID=1) and reassign content
# ⚠️  WARNING: This is a DESTRUCTIVE operation that removes all users except admin
if run_user_cleanup "User Cleanup (Delete Non-Admin Users)" false; then
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

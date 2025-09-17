#!/bin/bash

# MemberCore Daily Maintenance Script - Production Version
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

run_user_cleanup() {
    local description="$1"
    local critical="${2:-false}"
    
    log "Starting: $description"
    
    # Check if WP-CLI is working
    if ! wp --version &>/dev/null; then
        log_error "WP-CLI not found or not working"
        return 1
    fi
    
    # Check if WordPress is accessible
    if ! wp --path="$WORDPRESS_PATH" core version &>/dev/null; then
        log_error "WordPress not accessible at path: $WORDPRESS_PATH"
        return 1
    fi
    
    # Get list of users to delete (exclude admin user ID 1)
    local users_to_delete
    users_to_delete=$(wp --path="$WORDPRESS_PATH" user list --field=ID --exclude=1 2>&1)
    local wp_exit_code=$?
    
    # Debug logging (only in verbose mode)
    if [ "$VERBOSE" = true ]; then
        local total_users=$(wp --path="$WORDPRESS_PATH" user list --format=count 2>&1)
        log "Debug: Total users found: '$total_users'"
        log "Debug: WP-CLI exit code: $wp_exit_code"
    fi
    
    # Check if WP-CLI command failed
    if [ $wp_exit_code -ne 0 ]; then
        log_error "WP-CLI user list failed: $users_to_delete"
        return 1
    fi
    
    if [ -z "$users_to_delete" ]; then
        log "No users to delete (only admin user exists)"
        return 0
    fi
    
    local user_count=$(echo "$users_to_delete" | wc -w)
    log "Found $user_count users to delete (excluding admin ID=1)"
    
    if [ "$DRY_RUN" = true ]; then
        log "DRY RUN MODE: Would delete $user_count users"
        log "Sample user IDs to delete: $(echo $users_to_delete | head -n 1 | cut -d' ' -f1-10 | tr '\n' ' ')..."
        log "Highest user ID: $(echo $users_to_delete | tr ' ' '\n' | sort -n | tail -1)"
        log "Lowest user ID: $(echo $users_to_delete | tr ' ' '\n' | sort -n | head -1)"
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

run_post_cleanup() {
    local description="$1"
    local critical="${2:-false}"
    
    log "Starting: $description"
    
    # Check if WP-CLI is working
    if ! wp --version &>/dev/null; then
        log_error "WP-CLI not found or not working"
        return 1
    fi
    
    # Get list of posts to delete (mc-directory and mcpd-profile post types)
    local posts_to_delete
    posts_to_delete=$(wp --path="$WORDPRESS_PATH" post list --post_type='mc-directory,mcpd-profile' --format=ids 2>&1)
    local wp_exit_code=$?
    
    # Debug logging (only in verbose mode)
    if [ "$VERBOSE" = true ]; then
        local total_posts=$(wp --path="$WORDPRESS_PATH" post list --post_type='mc-directory,mcpd-profile' --format=count 2>&1)
        log "Debug: Total posts found: '$total_posts'"
        log "Debug: WP-CLI exit code: $wp_exit_code"
    fi
    
    # Check if WP-CLI command failed
    if [ $wp_exit_code -ne 0 ]; then
        log_error "WP-CLI post list failed: $posts_to_delete"
        return 1
    fi
    
    if [ -z "$posts_to_delete" ]; then
        log "No posts to delete (no mc-directory or mcpd-profile posts found)"
        return 0
    fi
    
    local post_count=$(echo "$posts_to_delete" | wc -w)
    log "Found $post_count posts to delete (types: mc-directory, mcpd-profile)"
    
    if [ "$DRY_RUN" = true ]; then
        log "DRY RUN MODE: Would delete $post_count posts"
        log "Sample post IDs to delete: $(echo $posts_to_delete | head -n 1 | cut -d' ' -f1-10 | tr '\n' ' ')..."
        if [ $post_count -gt 10 ]; then
            log "...and $(($post_count - 10)) more posts"
        fi
        log "DRY RUN: Would permanently delete (--force) all listed posts"
        return 0
    fi
    
    # WARNING: This is destructive - log it clearly
    log "⚠️  WARNING: Permanently deleting $post_count posts (mc-directory, mcpd-profile types)"
    
    # Execute the deletion
    if wp --path="$WORDPRESS_PATH" post delete $posts_to_delete --force $QUIET_FLAG; then
        log "✓ Completed: $description - Deleted $post_count posts"
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

run_option_cleanup() {
    local description="$1"
    local critical="${2:-false}"
    local options="mcpd_community_profile_type_added mcpd_social_profile_emails_added mcpd_community_directory_added meco_options"
    
    log "Starting: $description"
    
    # Check if WP-CLI is working
    if ! wp --version &>/dev/null; then
        log_error "WP-CLI not found or not working"
        return 1
    fi
    
    if [ "$DRY_RUN" = true ]; then
        log "DRY RUN MODE: Would delete options:"
        for option in $options; do
            log "  - Would delete option: $option"
        done
        return 0
    fi
    
    # Delete each option
    local deleted_count=0
    for option in $options; do
        log "Deleting option: $option"
        if wp --path="$WORDPRESS_PATH" option delete $option $QUIET_FLAG 2>/dev/null; then
            log "✓ Deleted option: $option"
            ((deleted_count++))
        else
            log "⚠️  Option not found or already deleted: $option"
        fi
    done
    
    log "✓ Completed: $description - Processed $deleted_count options"
    return 0
}

run_plugin_refresh() {
    local description="$1"
    local critical="${2:-false}"
    local plugins="spatie-ray user-switching code-snippets membercore membercore-cli membercore-profiles-and-directories"
    
    log "Starting: $description"
    
    # Check if WP-CLI is working
    if ! wp --version &>/dev/null; then
        log_error "WP-CLI not found or not working"
        return 1
    fi
    
    if [ "$DRY_RUN" = true ]; then
        log "DRY RUN MODE: Would deactivate and reactivate plugins:"
        for plugin in $plugins; do
            log "  - Would refresh plugin: $plugin"
        done
        return 0
    fi
    
    # Deactivate plugins
    log "Deactivating plugins: $plugins"
    if wp --path="$WORDPRESS_PATH" plugin deactivate $plugins $QUIET_FLAG; then
        log "✓ Plugins deactivated successfully"
    else
        log_error "Failed to deactivate some plugins (continuing anyway)"
    fi
    
    # Small delay to ensure deactivation is processed
    sleep 2
    
    # Reactivate plugins
    log "Reactivating plugins: $plugins"
    if wp --path="$WORDPRESS_PATH" plugin activate $plugins $QUIET_FLAG; then
        log "✓ Completed: $description - Plugins refreshed successfully"
        return 0
    else
        log_error "✗ Failed to reactivate some plugins"
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

# Debug: Check WordPress installation
if [ ! -f "$WORDPRESS_PATH/wp-config.php" ]; then
    log_error "WordPress not found at $WORDPRESS_PATH"
    log "Looking for wp-config.php in /home/pluginette-bolbf..."
    find /home/pluginette-bolbf -name "wp-config.php" -type f 2>/dev/null | head -3 | while read path; do
        log "Found WordPress at: $(dirname "$path")"
    done
    exit 1
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
TOTAL_COMMANDS=6  # Update this number when adding commands
COMPLETED_COMMANDS=0
FAILED_COMMANDS=0

# Command 1: User cleanup - DELETE ALL USERS EXCEPT ADMIN (ID=1) and reassign content
# ⚠️  WARNING: This is a DESTRUCTIVE operation that removes all users except admin
if run_user_cleanup "User Cleanup (Delete Non-Admin Users)" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 2: MemberCore Fresh - Reset MemberCore data with specific prefixes
# ⚠️  WARNING: This resets MemberCore database tables and data
if run_wp_command "MemberCore Fresh Reset" "meco fresh --prefixes=meco,mcpd --confirm" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 3: Post cleanup - DELETE posts with types mc-directory and mcpd-profile
# ⚠️  WARNING: This permanently deletes directory and profile posts
if run_post_cleanup "Post Cleanup (Delete Directories & Profiles)" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 4: Option cleanup - Delete specific WordPress options
# This cleans up specific MemberCore and MCPD options before plugin refresh
if run_option_cleanup "Option Cleanup (Delete MCPD/MemberCore Options)" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 5: Plugin refresh - Deactivate and reactivate key plugins
# This refreshes plugins and clears any cached states
if run_plugin_refresh "Plugin Refresh (Deactivate/Reactivate)" false; then
    ((COMPLETED_COMMANDS++))
else
    ((FAILED_COMMANDS++))
fi

# Command 6: Bulk create users from JSON with avatars and memberships
# This populates the site with demo users after the cleanup in random order
if run_wp_command "Bulk Create Users from JSON" "meco user bulk-create-from-json --upload-avatars --skip-existing --memberships=11,12,13,14 --membership-probability=75 --randomize --confirm" false; then
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

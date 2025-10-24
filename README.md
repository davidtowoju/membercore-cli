# MemberCore CLI

Extended WP-CLI commands for MemberCore management including membership assignment, user management, directory enrollment, job management, and more.

## Features

- **Membership Management**: Assign/remove memberships, list memberships, bulk operations
- **User Management**: Create users with memberships, view user info, manage roles
- **Directory Management**: Enroll users in directories, sync directories, manage enrollments
- **Job Management**: Monitor job queues, retry failed jobs, inspect job details
- **Transaction Management**: Expire transactions, view transaction history
- **System Management**: Reset data, view system information
- **Developer Tools**: Dry-run mode, detailed logging, data validation


## Commands Overview

### Base Commands (`wp meco`)

- `wp meco fresh` - Reset/truncate MemberCore data
- `wp meco info` - Show system information
- `wp meco list` - List all available commands

### Membership Commands (`wp meco membership`)

- `wp meco membership list` - List all memberships
- `wp meco membership assign` - Assign membership to user
- `wp meco membership remove` - Remove membership from user
- `wp meco membership info` - Show membership details
- `wp meco membership bulk-assign` - Bulk assign memberships

### User Commands (`wp meco user`)

- `wp meco user info` - Show user information with memberships
- `wp meco user create` - Create user with optional membership
- `wp meco user bulk-create` - Create multiple users with probabilistic membership assignment
- `wp meco user list` - List users with membership info
- `wp meco user delete` - Delete user

### Directory Commands (`wp meco directory`)

- `wp meco directory enroll` - Enroll users in a specific directory
- `wp meco directory sync` - Sync a specific directory
- `wp meco directory sync-all` - Sync all directories
- `wp meco directory unenroll-all` - Unenroll users from directories

### Job Management Commands (`wp meco jobs`)

- `wp meco jobs status` - Show job queue status and statistics
- `wp meco jobs watch` - Watch job status in real-time
- `wp meco jobs inspect` - Show detailed information about a specific job
- `wp meco jobs clear` - Clear jobs from the queue
- `wp meco jobs retry` - Retry failed jobs

### Transaction Commands (`wp meco transaction`)

- `wp meco transaction expire` - Expire specific transactions

## Usage Examples

### Membership Assignment

```bash
# Assign membership to user by ID
wp meco membership assign 123 456

# Assign membership to user by email
wp meco membership assign admin@example.com 456

# Assign membership with custom amount and expiration
wp meco membership assign john_doe 456 --amount=99.00 --expires=2024-12-31

# Assign lifetime membership
wp meco membership assign 123 456 --expires=never

# Dry run to preview changes
wp meco membership assign 123 456 --dry-run
```

### User Management

```bash
# Create user with membership
wp meco user create john_doe john@example.com --membership=456

# Create user with custom details
wp meco user create jane_doe jane@example.com --first-name=Jane --last-name=Doe --role=subscriber

# Show user info with memberships
wp meco user info john_doe --memberships --transactions

# List users with specific membership
wp meco user list --membership=456

# List users by role
wp meco user list --role=subscriber

# Create multiple users with membership assignment
wp meco user bulk-create 1000 --memberships=456 --membership-probability=80
wp meco user bulk-create 500 --prefix=member --domain=mysite.com
wp meco user bulk-create 100 --memberships=123,456,789 --membership-probability=75 --dry-run
```

### Directory Management

```bash
# Enroll users in a specific directory
wp meco directory enroll 123

# Sync a specific directory (re-evaluate enrollments)
wp meco directory sync 123

# Sync all directories
wp meco directory sync-all

# Unenroll all users from a specific directory
wp meco directory unenroll-all 123

# Unenroll all users from all directories
wp meco directory unenroll-all --confirm

# Preview directory enrollment changes
wp meco directory enroll 123 --dry-run
wp meco directory sync 123 --dry-run
```

### Job Management

```bash
# View job queue status
wp meco jobs status

# Watch jobs in real-time
wp meco jobs watch

# Filter job status by type
wp meco jobs status --status=failed
wp meco jobs status --class=DirectoryEnrollmentJob

# Inspect a specific job
wp meco jobs inspect 123
wp meco jobs inspect 123 --table=failed

# Retry failed jobs
wp meco jobs retry
wp meco jobs retry 123
wp meco jobs retry --class=DirectoryEnrollmentJob

# Clear jobs from queue
wp meco jobs clear --status=failed
wp meco jobs clear --older-than=24
```

### Bulk Operations

```bash
# Bulk assign membership to multiple users
wp meco membership bulk-assign 456 --users=1,2,3,admin@example.com

# Assign membership to all subscribers
wp meco membership bulk-assign 456 --role=subscriber

# Dry run bulk assignment
wp meco membership bulk-assign 456 --role=subscriber --dry-run

# Create multiple users with 80% membership assignment chance
wp meco user bulk-create 1000 --memberships=456 --membership-probability=80

# Create test users with custom prefix and domain
wp meco user bulk-create 500 --prefix=testuser --domain=staging.mysite.com --dry-run
```

### System Management

```bash
# Reset all MemberCore data
wp meco fresh

# Reset specific tables
wp meco fresh transactions subscriptions

# Show system information
wp meco info

# View membership details with users
wp meco membership info 456 --users
```

## Command Reference

### `wp meco membership assign`

Assign a membership to a user.

**Arguments:**
- `<user_id>` - User ID, email, or username
- `<membership_id>` - Membership ID

**Options:**
- `--amount=<amount>` - Transaction amount (default: 0.00)
- `--expires=<date>` - Expiration date (YYYY-MM-DD) or 'never'
- `--gateway=<gateway>` - Payment gateway (default: manual)
- `--dry-run` - Preview changes without applying

**Examples:**
```bash
wp meco membership assign 123 456
wp meco membership assign admin@example.com 456 --amount=99.00
wp meco membership assign john_doe 456 --expires=2024-12-31
wp meco membership assign 123 456 --expires=never --dry-run
```

### `wp meco membership remove`

Remove a membership from a user.

**Arguments:**
- `<user_id>` - User ID, email, or username
- `<membership_id>` - Membership ID

**Options:**
- `--dry-run` - Preview changes without applying

**Examples:**
```bash
wp meco membership remove 123 456
wp meco membership remove admin@example.com 456 --dry-run
```

### `wp meco membership bulk-assign`

Bulk assign membership to multiple users.

**Arguments:**
- `<membership_id>` - Membership ID

**Options:**
- `--users=<users>` - Comma-separated list of user IDs/emails/usernames
- `--role=<role>` - Assign to all users with this role
- `--dry-run` - Preview changes without applying

**Examples:**
```bash
wp meco membership bulk-assign 456 --users=1,2,3,admin@example.com
wp meco membership bulk-assign 456 --role=subscriber
wp meco membership bulk-assign 456 --role=subscriber --dry-run
```

### `wp meco directory enroll`

Enroll users in a specific directory.

**Arguments:**
- `<directory_id>` - The ID of the directory to enroll users in

**Options:**
- `--dry-run` - Preview what would happen without making changes

**Examples:**
```bash
wp meco directory enroll 123
wp meco directory enroll 123 --dry-run
```

### `wp meco directory sync`

Sync a specific directory (re-evaluate and update enrollments).

**Arguments:**
- `<directory_id>` - The ID of the directory to sync

**Options:**
- `--dry-run` - Preview what would happen without making changes

**Examples:**
```bash
wp meco directory sync 123
wp meco directory sync 123 --dry-run
```

### `wp meco directory sync-all`

Sync all directories.

**Options:**
- `--dry-run` - Preview what would happen without making changes

**Examples:**
```bash
wp meco directory sync-all
wp meco directory sync-all --dry-run
```

### `wp meco directory unenroll-all`

Unenroll all users from directories.

**Arguments:**
- `[<directory_id>]` - The ID of the directory to unenroll users from (optional)

**Options:**
- `--dry-run` - Preview what would happen without making changes
- `--confirm` - Skip confirmation prompt (use with caution)

**Examples:**
```bash
wp meco directory unenroll-all 123
wp meco directory unenroll-all 123 --dry-run
wp meco directory unenroll-all --confirm
```

### `wp meco jobs status`

Show job queue status and statistics.

**Options:**
- `--status=<status>` - Filter by job status (pending, working, complete, failed)
- `--class=<class>` - Filter by job class name
- `--format=<format>` - Output format (table, csv, json)

**Examples:**
```bash
wp meco jobs status
wp meco jobs status --status=failed
wp meco jobs status --class=DirectoryEnrollmentJob
wp meco jobs status --format=json
```

### `wp meco jobs watch`

Watch job status in real-time.

**Options:**
- `--interval=<seconds>` - Refresh interval in seconds (default: 5)
- `--changes-only` - Only show when statistics change
- `--status=<status>` - Filter by job status
- `--class=<class>` - Filter by job class

**Examples:**
```bash
wp meco jobs watch
wp meco jobs watch --interval=10
wp meco jobs watch --changes-only
wp meco jobs watch --status=pending
```

### `wp meco jobs inspect`

Show detailed information about a specific job.

**Arguments:**
- `<job_id>` - The ID of the job to inspect

**Options:**
- `--table=<table>` - Which table to look in (jobs, completed, failed)

**Examples:**
```bash
wp meco jobs inspect 123
wp meco jobs inspect 123 --table=failed
```

### `wp meco jobs clear`

Clear jobs from the queue.

**Options:**
- `--status=<status>` - Clear jobs with specific status
- `--class=<class>` - Clear jobs of specific class
- `--older-than=<hours>` - Clear jobs older than specified hours
- `--dry-run` - Show what would be deleted without actually deleting

**Examples:**
```bash
wp meco jobs clear --status=failed
wp meco jobs clear --class=DirectoryEnrollmentJob --dry-run
wp meco jobs clear --older-than=24
```

### `wp meco jobs retry`

Retry failed jobs.

**Arguments:**
- `[<job_id>]` - Retry a specific job by ID (optional)

**Options:**
- `--class=<class>` - Retry jobs of specific class only
- `--limit=<limit>` - Maximum number of jobs to retry (default: 10)
- `--dry-run` - Show what would be retried without actually retrying

**Examples:**
```bash
wp meco jobs retry
wp meco jobs retry 123
wp meco jobs retry --class=DirectoryEnrollmentJob
wp meco jobs retry --limit=5 --dry-run
```

### `wp meco user create`

Create a new user with optional membership assignment.

**Arguments:**
- `<username>` - Username for the new user
- `<email>` - Email address for the new user

**Options:**
- `--password=<password>` - Password (auto-generated if not provided)
- `--role=<role>` - User role (default: subscriber)
- `--membership=<membership_id>` - Membership ID to assign
- `--first-name=<name>` - First name
- `--last-name=<name>` - Last name
- `--display-name=<name>` - Display name
- `--send-email` - Send new user notification email

**Examples:**
```bash
wp meco user create john_doe john@example.com
wp meco user create jane_doe jane@example.com --membership=456
wp meco user create admin admin@example.com --role=administrator --send-email
```

### `wp meco user bulk-create`

Create multiple users with probabilistic membership assignment.

**Arguments:**
- `<count>` - Number of users to create

**Options:**
- `--prefix=<prefix>` - Prefix for usernames and emails (default: testuser)
- `--domain=<domain>` - Email domain for generated users (default: example.com)
- `--role=<role>` - Role for the new users (default: subscriber)
- `--memberships=<membership_ids>` - Comma-separated list of membership IDs to randomly assign
- `--membership-probability=<probability>` - Probability (0-100) that each user gets a membership (default: 50)
- `--batch-size=<size>` - Number of users to create per batch (default: 50)
- `--dry-run` - Show what would be created without actually creating users
- `--send-emails` - Send new user notification emails (not recommended for bulk)

**Examples:**
```bash
wp meco user bulk-create 1000
wp meco user bulk-create 1000 --memberships=456 --membership-probability=80
wp meco user bulk-create 500 --prefix=member --domain=mysite.com
wp meco user bulk-create 100 --memberships=123,456,789 --membership-probability=75
wp meco user bulk-create 1000 --batch-size=100 --dry-run
```

### `wp meco user info`

Show detailed user information including memberships.

**Arguments:**
- `<user_id>` - User ID, email, or username

**Options:**
- `--memberships` - Show detailed membership information
- `--transactions` - Show recent transaction history

**Examples:**
```bash
wp meco user info 123
wp meco user info admin@example.com --memberships
wp meco user info john_doe --transactions
```

## Development

### Project Structure

```
membercore-cli/
├── app/
│   ├── commands/
│   │   ├── Base.php          # Base command class
│   │   ├── Directory.php     # Directory management
│   │   ├── Membership.php    # Membership management
│   │   ├── User.php          # User management
│   │   ├── Transaction.php   # Transaction management
│   │   ├── Coaching.php      # Coaching commands
│   │   └── Courses.php       # Course commands
│   └── helpers/
│       ├── MembershipHelper.php  # Membership utilities
│       └── UserHelper.php        # User utilities
├── assets/
│   └── coaching-programs.json
├── composer.json
├── main.php
└── README.md
```

### Adding New Commands

1. Create a new command class in `app/commands/`
2. Extend the `Base` class for common functionality
3. Register the command in `main.php`
4. Add proper documentation and examples

### Running Tests

```bash
# Install development dependencies
composer install-dev

# Run linting
composer lint

# Run compatibility checks
composer compat

# Run all checks
composer check-all
```

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher
- WP-CLI 2.0 or higher
- MemberCore plugin (active)
- MemberCore Directory plugin (for directory commands)

## Support

For support and documentation, visit:
- [MemberCore Support](https://membercore.com/support/)
- [MemberCore Documentation](https://membercore.com/docs/)

## License

This plugin is licensed under the GPL-2.0-or-later license. 
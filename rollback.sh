#!/bin/bash

# PayStand Magento 2 Module Rollback Script

# Configuration
MAGENTO_ROOT=${MAGENTO_ROOT:-$(pwd)}
BACKUP_DIR="var/backups"
MODULE_NAME="PayStand_PayStandMagento"
PREVIOUS_VERSION=${1:-"3.4.0"} # Default to 3.4.0 if not specified

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Function to log messages
log() {
    echo -e "${2:-$GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Check if we're in the Magento root
if [ ! -f "$MAGENTO_ROOT/app/etc/env.php" ]; then
    log "This script must be run from Magento root directory" "$RED"
    exit 1
fi

# Check for required commands
for cmd in php composer mysql; do
    if ! command_exists $cmd; then
        log "$cmd is required but not installed." "$RED"
        exit 1
    fi
done

# Start rollback process
log "Starting rollback process for $MODULE_NAME..."

# Enable maintenance mode
log "Enabling maintenance mode..."
php bin/magento maintenance:enable || {
    log "Failed to enable maintenance mode" "$RED"
    exit 1
}

# Create backup
log "Creating backup..." "$YELLOW"
php bin/magento setup:backup --code --db --media || {
    log "Backup failed" "$RED"
    php bin/magento maintenance:disable
    exit 1
}

# Remove current version
log "Removing current version..." "$YELLOW"
composer remove paystand/paystandmagento || {
    log "Failed to remove current version" "$RED"
    php bin/magento maintenance:disable
    exit 1
}

# Clear directories
log "Cleaning up directories..." "$YELLOW"
rm -rf app/code/PayStand
rm -rf generated/code/PayStand
rm -rf var/cache/*
rm -rf var/page_cache/*

# Install previous version
log "Installing previous version ($PREVIOUS_VERSION)..." "$YELLOW"
composer require "paystand/paystandmagento:$PREVIOUS_VERSION" || {
    log "Failed to install previous version" "$RED"
    php bin/magento maintenance:disable
    exit 1
}

# Run setup upgrade
log "Running setup:upgrade..." "$YELLOW"
php bin/magento setup:upgrade || {
    log "Setup upgrade failed" "$RED"
    php bin/magento maintenance:disable
    exit 1
}

# Compile code
log "Compiling code..." "$YELLOW"
php bin/magento setup:di:compile || {
    log "Compilation failed" "$RED"
    php bin/magento maintenance:disable
    exit 1
}

# Deploy static content
log "Deploying static content..." "$YELLOW"
php bin/magento setup:static-content:deploy || {
    log "Static content deployment failed" "$RED"
    php bin/magento maintenance:disable
    exit 1
}

# Clear cache
log "Clearing cache..." "$YELLOW"
php bin/magento cache:clean
php bin/magento cache:flush

# Fix permissions
log "Fixing permissions..." "$YELLOW"
find var generated vendor pub/static pub/media app/etc -type f -exec chmod g+w {} +
find var generated vendor pub/static pub/media app/etc -type d -exec chmod g+ws {} +

# Disable maintenance mode
log "Disabling maintenance mode..." "$YELLOW"
php bin/magento maintenance:disable

# Verify rollback
MODULE_STATUS=$(php bin/magento module:status $MODULE_NAME)
if [[ $MODULE_STATUS == *"Module is enabled"* ]]; then
    log "Rollback completed successfully" "$GREEN"
    log "Module version: $PREVIOUS_VERSION" "$GREEN"
    log "Please verify the module functionality" "$YELLOW"
else
    log "Rollback may have issues. Please check module status" "$RED"
    log "Module status: $MODULE_STATUS" "$RED"
fi

# Final instructions
log "Rollback complete. Please:" "$GREEN"
log "1. Verify module functionality" "$YELLOW"
log "2. Check logs for any errors" "$YELLOW"
log "3. Test payment processing" "$YELLOW"
log "4. Contact support if you need assistance" "$YELLOW" 
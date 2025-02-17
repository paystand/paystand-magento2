# PayStand Magento 2 Module - Installation Guide

## Prerequisites
- Magento 2.4.4 or higher
- PHP 7.4 or higher (PHP 8.3 supported)
- Composer 2.x
- PayStand merchant account

## Pre-Installation

### 1. Backup
```bash
# Backup database
bin/magento setup:backup --db

# Backup code
bin/magento setup:backup --code

# Backup media files
bin/magento setup:backup --media
```

### 2. Enable Maintenance Mode
```bash
bin/magento maintenance:enable
```

## Installation

### Fresh Installation
```bash
# 1. Require the module
composer require paystand/paystandmagento:^3.5.0

# 2. Enable the module
bin/magento module:enable PayStand_PayStandMagento

# 3. Run setup upgrade
bin/magento setup:upgrade

# 4. Compile code
bin/magento setup:di:compile

# 5. Deploy static content
bin/magento setup:static-content:deploy

# 6. Clear cache
bin/magento cache:clean
bin/magento cache:flush
```

### Upgrading from Previous Version
```bash
# 1. Remove existing module
composer remove paystand/paystandmagento

# 2. Clear existing files
rm -rf app/code/PayStand

# 3. Install new version
composer require paystand/paystandmagento:^3.5.0

# 4. Run setup upgrade
bin/magento setup:upgrade

# 5. Compile code
bin/magento setup:di:compile

# 6. Deploy static content
bin/magento setup:static-content:deploy

# 7. Clear cache
bin/magento cache:clean
bin/magento cache:flush
```

## Post-Installation

### 1. Verify Installation
```bash
# Check module status
bin/magento module:status PayStand_PayStandMagento

# Verify version
composer show paystand/paystandmagento

# Check for errors
tail -f var/log/system.log
tail -f var/log/exception.log
```

### 2. Configure PayStand
1. Go to Admin Panel > Stores > Configuration > Sales > Payment Methods
2. Find PayStand section
3. Enter your credentials:
   - Public Key
   - Private Key
   - Customer ID
4. Save configuration
5. Clear cache again:
   ```bash
   bin/magento cache:clean
   ```

### 3. Test Payment Flow
1. Create a test order in sandbox mode
2. Verify webhook functionality
3. Test refund process

## Rollback Procedure

If you encounter issues, follow these steps to rollback:

### 1. Using Backup
```bash
# Restore code backup
bin/magento setup:rollback --code-file=<backup-filename>

# Restore database backup
bin/magento setup:rollback --db-file=<backup-filename>

# Restore media backup
bin/magento setup:rollback --media-file=<backup-filename>
```

### 2. Manual Rollback
```bash
# Remove new version
composer remove paystand/paystandmagento

# Install previous version
composer require paystand/paystandmagento:<previous-version>

# Run setup upgrade
bin/magento setup:upgrade

# Recompile code
bin/magento setup:di:compile

# Redeploy static content
bin/magento setup:static-content:deploy

# Clear cache
bin/magento cache:clean
bin/magento cache:flush
```

## Troubleshooting

### Common Issues

1. **Compilation Errors**
   ```bash
   # Clear generated code
   rm -rf generated/*
   
   # Recompile
   bin/magento setup:di:compile
   ```

2. **Cache Issues**
   ```bash
   # Full cache clear
   rm -rf var/cache/*
   rm -rf var/page_cache/*
   bin/magento cache:clean
   bin/magento cache:flush
   ```

3. **Permission Issues**
   ```bash
   # Fix permissions
   find var generated vendor pub/static pub/media app/etc -type f -exec chmod g+w {} +
   find var generated vendor pub/static pub/media app/etc -type d -exec chmod g+ws {} +
   ```

### Support

If you encounter any issues:
1. Check logs at `var/log/`
2. Contact PayStand support with:
   - Magento version
   - PHP version
   - Module version
   - Error logs
   - Steps to reproduce

## Version Compatibility Matrix

| Magento Version | PHP Version | Module Version |
|----------------|-------------|----------------|
| 2.4.7-p4       | 8.3         | 3.5.0         |
| 2.4.6          | 8.2         | 3.5.0         |
| 2.4.5          | 8.1         | 3.5.0         |
| 2.4.4          | 7.4         | 3.5.0         | 
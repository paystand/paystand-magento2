#!/bin/bash

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Function to log messages
log() {
    echo -e "${2:-$GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

# Step 1: Validate structural changes
log "Step 1: Validating structural changes..." "$YELLOW"
php validate-changes.php
if [ $? -ne 0 ]; then
    log "Structural validation failed" "$RED"
    exit 1
fi

# Step 2: Run unit tests
log "Step 2: Running unit tests..." "$YELLOW"
if [ -d "Test/Unit" ]; then
    ../vendor/bin/phpunit Test/Unit/
    if [ $? -ne 0 ]; then
        log "Unit tests failed" "$RED"
        exit 1
    fi
fi

# Step 3: Test class loading
log "Step 3: Testing class loading..." "$YELLOW"
php -r "
require_once 'registration.php';
\$classes = [
    'PayStand\PayStandMagento\Controller\Webhook\PayStand',
    'PayStand\PayStandMagento\Model\Compatibility\Version',
    'PayStand\PayStandMagento\Model\Compatibility\VersionInterface'
];
\$success = true;
foreach (\$classes as \$class) {
    if (!class_exists(\$class) && !interface_exists(\$class)) {
        echo \"Failed to load: {\$class}\n\";
        \$success = false;
    }
}
exit(\$success ? 0 : 1);
"
if [ $? -ne 0 ]; then
    log "Class loading test failed" "$RED"
    exit 1
fi

# Step 4: Verify backward compatibility
log "Step 4: Testing backward compatibility..." "$YELLOW"
if [ -d "Controller/webhook" ]; then
    log "Checking old webhook directory structure..." "$YELLOW"
    php -r "
    require_once 'registration.php';
    \$oldClass = 'PayStand\PayStandMagento\Controller\webhook\PayStand';
    \$newClass = 'PayStand\PayStandMagento\Controller\Webhook\PayStand';
    if (!class_exists(\$oldClass) && !class_exists(\$newClass)) {
        echo \"Neither old nor new controller class loadable\n\";
        exit(1);
    }
    "
    if [ $? -ne 0 ]; then
        log "Backward compatibility test failed" "$RED"
        exit 1
    fi
fi

# Step 5: Verify interface implementation
log "Step 5: Verifying interface implementation..." "$YELLOW"
php -r "
require_once 'registration.php';
\$controller = 'PayStand\PayStandMagento\Controller\Webhook\PayStand';
if (class_exists(\$controller)) {
    \$implements = class_implements(\$controller);
    if (!isset(\$implements['Magento\Framework\App\Action\HttpPostActionInterface'])) {
        echo \"Controller does not implement HttpPostActionInterface\n\";
        exit(1);
    }
}
"
if [ $? -ne 0 ]; then
    log "Interface implementation test failed" "$RED"
    exit 1
fi

# Step 6: Test type declarations
log "Step 6: Testing type declarations..." "$YELLOW"
php -r "
require_once 'registration.php';
\$version = new PayStand\PayStandMagento\Model\Compatibility\Version(
    new class implements Magento\Framework\App\ProductMetadataInterface {
        public function getVersion() { return '2.4.7'; }
        public function getEdition() { return 'Community'; }
        public function getName() { return 'Magento'; }
    }
);
try {
    \$version->isMagentoVersion('2.4.7');
    \$version->isPhpVersion('8.3');
    \$version->getMagentoVersion();
} catch (TypeError \$e) {
    echo \"Type declaration test failed: {\$e->getMessage()}\n\";
    exit(1);
}
"
if [ $? -ne 0 ]; then
    log "Type declaration test failed" "$RED"
    exit 1
fi

# All tests passed
log "All tests completed successfully! ✅" "$GREEN"
log "Note: Please still test in a staging environment before production deployment" "$YELLOW"

# Recommendations
echo -e "\nRecommended next steps:"
echo "1. Deploy to staging environment"
echo "2. Process a test payment"
echo "3. Verify webhook functionality"
echo "4. Check logs for any warnings" 
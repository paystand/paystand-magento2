<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function expectOrderability(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function source(string $root, string $relative): string
{
    $contents = file_get_contents($root . '/' . $relative);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $relative);
    }
    return $contents;
}

function section(string $source, string $start, string $end): string
{
    $from = strpos($source, $start);
    expectOrderability($from !== false, 'Missing section start: ' . $start);
    $to = strpos($source, $end, $from + strlen($start));
    expectOrderability($to !== false, 'Missing section end: ' . $end);
    return substr($source, $from, $to - $from);
}

function appearsInOrder(string $source, array $needles, string $message): void
{
    $offset = 0;
    foreach ($needles as $needle) {
        $position = strpos($source, $needle, $offset);
        expectOrderability($position !== false, $message . ': missing ' . $needle);
        $offset = $position + strlen($needle);
    }
}

$schema = simplexml_load_file($root . '/etc/db_schema.xml');
expectOrderability($schema !== false, 'Declarative schema is invalid XML');
$tables = $schema->xpath('/schema/table[@name="paystand_checkout_attempt"]');
expectOrderability(count($tables) === 1, 'Checkout-attempt table missing');
$table = $tables[0];
expectOrderability(count($table->xpath('./constraint[@xsi:type="foreign"]')) === 0,
    'Payment memory must survive quote cleanup; foreign key found');
expectOrderability(count($table->xpath('./constraint[@referenceId="PAYSTAND_CHECKOUT_ATTEMPT_QUOTE_UNQ"]')) === 1,
    'Quote start-once uniqueness missing');
expectOrderability(count($table->xpath('./constraint[@referenceId="PAYSTAND_CHECKOUT_ATTEMPT_TOKEN_UNQ"]')) === 1,
    'Attempt-token uniqueness missing');
$outboxTables = $schema->xpath('/schema/table[@name="paystand_checkout_outbox"]');
expectOrderability(count($outboxTables) === 1, 'Checkout lifecycle outbox missing');
expectOrderability(count($outboxTables[0]->xpath('./constraint[@xsi:type="foreign"]')) === 0,
    'Lifecycle outbox must survive source-row cleanup; foreign key found');
expectOrderability(count($outboxTables[0]->xpath('./constraint[@referenceId="PAYSTAND_CHECKOUT_OUTBOX_EVENT_KEY_UNQ"]')) === 1,
    'Outbox idempotency uniqueness missing');

$whitelist = json_decode(source($root, 'etc/db_schema_whitelist.json'), true, 32, JSON_THROW_ON_ERROR);
expectOrderability(isset($whitelist['paystand_checkout_attempt']), 'Declarative schema whitelist missing attempt table');
expectOrderability(isset($whitelist['paystand_checkout_outbox']), 'Declarative schema whitelist missing outbox table');

$service = source($root, 'Model/Checkout/AttemptService.php');
expectOrderability(substr_count($service, 'validateBeforeSubmit($quote)') >= 2,
    'Magento validateBeforeSubmit is not enforced at prepare/start');
expectOrderability(strpos($service, "'cart_lock_' . \$quoteId") !== false,
    'Checkout attempt does not share Magento cart mutex');
expectOrderability(strpos($service, 'transitionToProviderStarted(') !== false,
    'Atomic provider-start transition missing');
expectOrderability(strpos($service, 'active-session-quote-changed') !== false,
    'Prepared attempt can start after the checkout session changes carts');
expectOrderability(strpos($service, 'quote-changed-after-provider-start') !== false
    && strpos($service, 'quote-unorderable-after-provider-start') !== false,
    'Post-provider quote drift is not held');
expectOrderability(strpos($service, 'unrecognized-provider-status') !== false,
    'Unknown provider callback status can erase payment memory');
expectOrderability(strpos($service, "'publishableKey' => \$publishableKey") !== false
    && strpos($service, "'presetCustom' => \$preset") !== false,
    'Paystand public configuration is not bound to the Magento snapshot');
foreach (['api.paystand.', 'curl_', 'file_get_contents(\'http', 'refund'] as $remoteOperation) {
    expectOrderability(stripos($service, $remoteOperation) === false,
        'Orderability authority performs remote/refund work: ' . $remoteOperation);
}

$repository = source($root, 'Model/Checkout/AttemptRepository.php');
appearsInOrder($repository, [
    "STATE_PREPARED = 'prepared'",
    "STATE_PROVIDER_STARTED = 'provider_started'",
    "STATE_BROWSER_REPORTED = 'browser_reported'",
    "STATE_ORDER_PLACED = 'order_placed'",
    "STATE_HELD = 'held'"
], 'Attempt lifecycle is incomplete or reordered');
expectOrderability(strpos($repository, "'version = ?' => \$version") !== false,
    'Optimistic transition version guard missing');
expectOrderability(strpos($repository, "'payment_may_be_started = ?' => 1") !== false,
    'Provider start permission is not consumed atomically');
appearsInOrder($repository, [
    'beginTransaction()',
    "'state' => self::STATE_ORDER_PLACED",
    "'eventType' => 'magento.order_placed'",
    'insertOnDuplicate(',
    'commit()'
], 'Order lifecycle and outbox are not committed together');
expectOrderability(strpos($repository, "':order_placed:v1'") !== false,
    'Outbox event lacks a deterministic idempotency key');
expectOrderability(strpos($repository, 'repairCommittedOrders') !== false
    && strpos($repository, 'checkout_outbox.outbox_id IS NULL') !== false,
    'Order-commit/outbox repair scan missing');

$luma = source($root, 'view/frontend/web/js/view/payment/method-renderer/paystandmagento-directpost.js');
$lumaLoad = section($luma, 'async function loadCheckout()', 'const ORDER_CONFIRM_MAX_ATTEMPTS');
appearsInOrder($lumaLoad, [
    "postMagento('paystandmagento/checkout/preparepayment'",
    'fetchServerQuoteData()',
    "postMagento('paystandmagento/checkout/startpayment'",
    'activeAttempt = started.attempt',
    'initCheckout(buildPaystandCheckoutConfig(serverQuote, activeAttempt))'
], 'Luma provider-open sequence is not fail-closed');
expectOrderability(strpos($luma, 'fallbackToClientSnapshot') === false,
    'Luma retains a client-snapshot payment fallback');
expectOrderability(strpos($luma, 'fetchQuotePaymentStatus') === false,
    'Luma retains the former fail-open recharge check');
expectOrderability(strpos($luma, 'paymentAmount": grandTotal.toString()') !== false
    && strpos($luma, 'const grandTotal = attempt && attempt.amount') !== false,
    'Luma amount is not sourced from the Magento attempt');
expectOrderability(strpos($luma, '"publishableKey": attempt.publishableKey') !== false
    && strpos($luma, '"presetCustom": attempt.presetCustom') !== false,
    'Luma provider configuration is not sourced from the Magento attempt');
expectOrderability(strpos($luma, 'showStartAmbiguityModal()') !== false
    && strpos($luma, 'Do not retry payment for this cart') !== false,
    'Luma does not surface a consumed-start ambiguity safely');
expectOrderability(strpos($luma, "postMagento('paystandmagento/checkout/attemptstatus'") !== false
    && strpos($luma, "lifecycleState === 'order_placed'") !== false,
    'Luma does not confirm orders through durable attempt status');
expectOrderability(strpos($luma, 'const qid = activeAttempt ? activeAttempt.quoteId') !== false,
    'Luma trusts provider-returned quote identity over the Magento attempt');

$hyva = source($root, 'view/frontend/web/js/hyva-checkout/paystand-method.js');
$hyvaBuild = section($hyva, 'async function buildPaystandConfig()', '/** Display an inline error');
appearsInOrder($hyvaBuild, [
    'postMagento(urls.preparePayment',
    'fetch(urls.getQuoteData',
    'postMagento(urls.startPayment',
    'activeAttempt = started.attempt',
    '"paymentAmount": activeAttempt.amount'
], 'Hyva provider-open sequence is not fail-closed');
expectOrderability(strpos($hyva, 'if (activeAttempt)') !== false
    && strpos($hyva, 'Do not start another payment for this cart') !== false,
    'Hyva can re-enable payment after a consumed start claim');
expectOrderability(strpos($hyva, '"publishableKey": activeAttempt.publishableKey') !== false
    && strpos($hyva, '"presetCustom": activeAttempt.presetCustom') !== false,
    'Hyva provider configuration is not sourced from the Magento attempt');
expectOrderability(strpos($hyva, 'Paystand may have started. Do not retry payment') !== false,
    'Hyva does not surface a consumed-start ambiguity safely');
expectOrderability(strpos($hyva, 'quote: activeAttempt && activeAttempt.quoteId') !== false
    && strpos($hyva, 'Do not pay again; contact support.') !== false,
    'Hyva completion is not bound to Magento identity or safe retry guidance');
expectOrderability(strpos($luma, '"attemptToken": attempt.attemptToken') === false
    && strpos($hyva, '"attemptToken": activeAttempt.attemptToken') === false,
    'Private Magento attempt token is sent to Paystand metadata');

$save = source($root, 'Controller/Checkout/SavePaymentData.php');
appearsInOrder($save, [
    'recordBrowserPayment(',
    'STATE_BROWSER_REPORTED',
    "setData('paystand_adjustment'",
    '$this->cartRepository->save($quote)'
], 'Provider memory is not gated before quote mutation');
expectOrderability(strpos($save, 'PAYMENT_ORDERABILITY_CHANGED') !== false,
    'Held post-provider attempt is not surfaced');
expectOrderability(strpos($save, "const PAYMENT_ID_PATTERN = '/^[a-z0-9_-]{16,160}$/i'") !== false,
    'Quote and durable-attempt payment ID contracts differ');
expectOrderability(strpos($save, 'if (!$quoteIdIncoming || !$paymentId || !$paymentStatus || !$attemptToken)') !== false,
    'Optional payer identity can block durable payment memory');

$events = source($root, 'etc/events.xml');
expectOrderability(strpos($events, 'paystand_checkout_attempt_ordered') !== false,
    'Order lifecycle marker is not registered');
expectOrderability(strpos($events, '<event name="sales_model_service_quote_submit_success">') !== false,
    'Order lifecycle marker runs before Magento saves the order');
$observer = source($root, 'Observer/MarkCheckoutAttemptOrdered.php');
expectOrderability(strpos($observer, 'catch (\\Throwable $error)') !== false,
    'Order lifecycle marker can break committed order flow');
$cron = source($root, 'Cron/RepairCheckoutLifecycle.php');
$crontab = source($root, 'etc/crontab.xml');
expectOrderability(strpos($cron, 'repairCommittedOrders(100)') !== false
    && strpos($crontab, 'paystand_checkout_lifecycle_repair') !== false,
    'Bounded local lifecycle repair is not scheduled');
foreach ([$cron, $observer] as $localLifecycleSource) {
    expectOrderability(stripos($localLifecycleSource, 'api.paystand.') === false
        && stripos($localLifecycleSource, 'refund') === false,
        'Local lifecycle code contains a provider/refund effect');
}
$statusController = source($root, 'Controller/Checkout/AttemptStatus.php');
expectOrderability(strpos($statusController, 'HttpPostActionInterface') !== false
    && strpos($statusController, "'attempt-status-unavailable'") !== false,
    'Session-bound fail-closed attempt status endpoint missing');
foreach ([
    $statusController,
    source($root, 'Controller/Checkout/PreparePayment.php'),
    source($root, 'Controller/Checkout/StartPayment.php'),
    $save
] as $controllerSource) {
    expectOrderability(strpos($controllerSource, 'Response::HTTP_CONFLICT') === false
        && strpos($controllerSource, 'Response::HTTP_NOT_FOUND') === false
        && strpos($controllerSource, 'Response::HTTP_BAD_REQUEST') === false,
        'Controller uses HTTP constants absent from Magento Webapi Response');
}

echo json_encode([
    'ok' => true,
    'authoritativePreflight' => true,
    'atomicStartOnce' => true,
    'postProviderDriftHeld' => true,
    'durableProviderMemory' => true,
    'transactionalOutbox' => true,
    'commitEnqueueRepair' => true,
    'lumaFailClosed' => true,
    'hyvaFailClosed' => true,
    'remoteWrites' => 0,
    'refundCalls' => 0
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

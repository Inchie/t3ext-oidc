<?php

declare(strict_types=1);

use Causal\Oidc\Tests\Functional\AbstractFunctionalTestBase;

// The test instance is bootstrapped again for every frontend request, which resets
// $GLOBALS['TYPO3_CONF_VARS'] to the content of its settings file. A settings file cannot
// hold a closure, so the running test case supplies its HTTP handler and settings here.
$GLOBALS['TYPO3_CONF_VARS'] = array_replace_recursive(
    $GLOBALS['TYPO3_CONF_VARS'],
    AbstractFunctionalTestBase::$instanceConfiguration
);

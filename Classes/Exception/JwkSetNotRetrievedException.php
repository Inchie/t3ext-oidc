<?php

declare(strict_types=1);

namespace Causal\Oidc\Exception;

/**
 * Its message is the reason of the failure and never carries key or token content.
 */
class JwkSetNotRetrievedException extends \RuntimeException {}

<?php

declare(strict_types=1);

namespace Causal\Oidc\Exception;

/**
 * Its message is the reason of the rejection and never carries token content.
 */
class LogoutTokenRejectedException extends \RuntimeException {}

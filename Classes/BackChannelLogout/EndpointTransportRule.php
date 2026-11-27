<?php

declare(strict_types=1);

namespace Causal\Oidc\BackChannelLogout;

/**
 * Decides whether an endpoint is reached over HTTPS with certificate validation.
 */
final readonly class EndpointTransportRule
{
    public function holdsFor(string $endpointUrl): bool
    {
        $scheme = parse_url($endpointUrl, PHP_URL_SCHEME);
        if (!is_string($scheme) || strtolower($scheme) !== 'https') {
            return false;
        }

        // Evaluated the way TYPO3's GuzzleClientFactory evaluates the setting: true or a CA bundle path enables validation.
        $verify = $GLOBALS['TYPO3_CONF_VARS']['HTTP']['verify'] ?? true;
        $verify = filter_var($verify, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $verify;

        return $verify !== false;
    }
}

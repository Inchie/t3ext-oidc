<?php

declare(strict_types=1);

namespace Causal\Oidc\BackChannelLogout;

use Causal\Oidc\Exception\JwkSetNotRetrievedException;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Retrieves the JWK Set of the identity provider over a trusted transport. Nothing is kept:
 * every call is one request to the JWKS URL.
 */
final readonly class JwkSetRetriever
{
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TOTAL_TIMEOUT_SECONDS = 5;

    public function __construct(
        private EndpointTransportRule $transportRule,
        private RequestFactory $requestFactory,
    ) {}

    /**
     * @return array<mixed> The decoded JWK Set document
     * @throws JwkSetNotRetrievedException
     */
    public function retrieve(string $jwksUrl): array
    {
        if (!$this->transportRule->holdsFor($jwksUrl)) {
            throw new JwkSetNotRetrievedException(
                'The JWKS URL must use HTTPS with certificate validation. (6d2826c6-3f60-41f9-a5aa-01282c0ba2cc)'
            );
        }

        try {
            $response = $this->requestFactory->request($jwksUrl, 'GET', [
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::TOTAL_TIMEOUT_SECONDS,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            $body = (string)$response->getBody();
        } catch (\Throwable $e) {
            throw new JwkSetNotRetrievedException(
                'The request to the JWKS URL failed: ' . $e::class . ' (a9f8f347-12fb-4d3f-b693-57f4ea2321d1)',
                0,
                $e
            );
        }
        if ($status !== 200) {
            throw new JwkSetNotRetrievedException(
                'The JWKS URL answered with status ' . $status . '. (74513f49-a640-4a4a-826f-954c6546d971)'
            );
        }

        $jwkSet = json_decode($body, true);
        if (!is_array($jwkSet) || !is_array($jwkSet['keys'] ?? null)) {
            throw new JwkSetNotRetrievedException('The JWKS URL did not answer with a JWK Set. (471956fa-15ac-4e69-80ca-35550f1d464a)');
        }
        return $jwkSet;
    }
}

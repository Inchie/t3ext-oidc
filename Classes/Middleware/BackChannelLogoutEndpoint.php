<?php

declare(strict_types=1);

namespace Causal\Oidc\Middleware;

use Causal\Oidc\BackChannelLogout\JwkSetRetriever;
use Causal\Oidc\BackChannelLogout\LogoutTokenValidator;
use Causal\Oidc\BackChannelLogout\SessionLogout;
use Causal\Oidc\Exception\JwkSetNotRetrievedException;
use Causal\Oidc\Exception\LogoutTokenRejectedException;
use Causal\Oidc\OidcConfiguration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\Response;

/**
 * The relying-party endpoint of OpenID Connect Back-Channel Logout 1.0: the identity provider
 * posts a signed logout token, and the frontend sessions of its sid end.
 */
final readonly class BackChannelLogoutEndpoint implements MiddlewareInterface
{
    private const CONTENT_TYPE = 'application/x-www-form-urlencoded';

    public function __construct(
        private OidcConfiguration $settings,
        private LogoutTokenValidator $logoutTokenValidator,
        private JwkSetRetriever $jwkSetRetriever,
        private SessionLogout $sessionLogout,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (
            !$this->settings->isBackChannelLogoutConfigured()
            || $request->getMethod() !== 'POST'
            || $request->getUri()->getPath() !== $this->settings->backChannelLogoutPath
        ) {
            return $handler->handle($request);
        }

        try {
            $sid = $this->validatedSid($request);
        } catch (LogoutTokenRejectedException|JwkSetNotRetrievedException $e) {
            // The messages of both name the reason and carry no token content.
            $this->logger->warning('Back-Channel Logout rejected: ' . $e->getMessage());
            return $this->answer(400);
        }

        // No session association is read before the token is fully validated.
        return $this->answer($this->sessionLogout->endSessionsOf($sid) ? 200 : 400);
    }

    /**
     * @return string The sid of the validated logout token
     * @throws LogoutTokenRejectedException
     * @throws JwkSetNotRetrievedException
     */
    private function validatedSid(ServerRequestInterface $request): string
    {
        $logoutToken = $this->logoutTokenOf($request);
        // What is recognisably malformed without key material causes no outbound request.
        $this->logoutTokenValidator->assertWellFormed($logoutToken);

        return $this->logoutTokenValidator->validate(
            $logoutToken,
            $this->jwkSetRetriever->retrieve($this->settings->endpointJwks),
            $this->settings->issuer,
            $this->settings->clientKey,
        );
    }

    /**
     * @throws LogoutTokenRejectedException
     */
    private function logoutTokenOf(ServerRequestInterface $request): string
    {
        $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($mediaType !== self::CONTENT_TYPE) {
            throw new LogoutTokenRejectedException('The request is not form-encoded. (9e658040-368e-42ff-8969-db7875feb969)');
        }
        $body = $request->getParsedBody();
        $logoutToken = is_array($body) ? ($body['logout_token'] ?? null) : null;
        if (!is_string($logoutToken) || $logoutToken === '') {
            throw new LogoutTokenRejectedException('The request carries no logout_token. (bc4e6613-bcfa-4c6e-9fa6-dca633bbc8d7)');
        }
        return $logoutToken;
    }

    private function answer(int $status): ResponseInterface
    {
        return (new Response())->withStatus($status)->withHeader('Cache-Control', 'no-store');
    }
}

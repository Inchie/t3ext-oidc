<?php

declare(strict_types=1);

namespace Causal\Oidc\BackChannelLogout;

use Causal\Oidc\Exception\SessionAssociationNotStoredException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\Event\AfterUserLoggedInEvent;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Session\SessionManager;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Associates the session the Core persisted for a frontend login with the identity-provider
 * session of that login, and ends the login where the association cannot be stored.
 *
 * Listens to the Core's AfterUserLoggedInEvent: the Core's frontend authentication dispatches it
 * for a login request, after it persisted the session and before it hands the request on.
 */
final readonly class LoginSessionAssociator
{
    /**
     * Key of the user candidate carrying the validated identity-provider session identifier
     *
     * @internal
     */
    public const USER_KEY_SID = 'backChannelLogoutSid';

    /**
     * Key of the user candidate telling whether the session of the request had a user before the login
     *
     * @internal
     */
    public const USER_KEY_SESSION_HAD_USER = 'backChannelLogoutSessionHadUser';

    public function __construct(
        private SessionAssociationRepository $associations,
        private SessionManager $sessionManager,
        private Context $context,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(AfterUserLoggedInEvent $event): void
    {
        $frontendUser = $event->getUser();
        $request = $event->getRequest();
        if (!$frontendUser instanceof FrontendUserAuthentication || $request === null) {
            return;
        }
        // Only the user of a login this request performed carries the sid: the Core takes it over from the user candidate.
        $sid = $frontendUser->user[self::USER_KEY_SID] ?? null;
        if (!is_string($sid)) {
            return;
        }

        $sessionIdentifier = $frontendUser->getSession()->getIdentifier();
        try {
            if ($frontendUser->user[self::USER_KEY_SESSION_HAD_USER] ?? false) {
                // The Core re-creates such a session for the new login under the identifier it had.
                $this->associations->storeAsOnlyAssociationOfSession($sid, $sessionIdentifier);
            } else {
                $this->associations->store($sid, $sessionIdentifier);
            }
        } catch (SessionAssociationNotStoredException $e) {
            $this->logger->error('Login denied: ' . $e->getMessage());
            $this->endLogin($frontendUser, $sessionIdentifier, $request);
        }
    }

    /**
     * Removes the session of the login and leaves the request without a user.
     */
    private function endLogin(
        FrontendUserAuthentication $frontendUser,
        #[\SensitiveParameter]
        string $sessionIdentifier,
        ServerRequestInterface $request,
    ): void {
        try {
            // Removed here as well: a listener of the Core's logout event may keep logoff() from removing the session.
            $this->sessionManager->getSessionBackend('FE')->remove($sessionIdentifier);
            $frontendUser->logoff();
        } catch (\Throwable $e) {
            // The class only: the message of a session backend exception carries the session identifier.
            $this->logger->critical('Login denied, but its session could not be removed', ['exception' => $e::class]);
        }
        // Whatever happened above, this request has no user and hands out no session cookie.
        $frontendUser->user = null;
        $frontendUser->removeCookie();
        // The Core built the groups and the user aspect of the request before it dispatched the event.
        $frontendUser->fetchGroupData($request);
        $this->context->setAspect('frontend.user', $frontendUser->createUserAspect());
    }
}

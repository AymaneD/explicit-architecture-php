<?php

declare(strict_types=1);

/*
 * This file is part of the Explicit Architecture POC,
 * which is created on top of the Symfony Demo application.
 *
 * (c) Herberto Graça <herberto.graca@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Acme\App\Infrastructure\Auth\Authentication;

use Acme\App\Core\Component\User\Application\Repository\UserRepositoryInterface;
use Acme\App\Core\Component\User\Domain\User\User;
use Acme\App\Core\Port\Auth\AuthenticationException;
use Acme\App\Core\Port\Auth\AuthenticationServiceInterface;
use Acme\App\Core\Port\Auth\NoUserAuthenticatedException;
use Acme\App\Core\SharedKernel\Component\User\Domain\User\UserId;
use League\OAuth2\Server\Repositories\UserRepositoryInterface as OauthUserRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException as SymfonyAuthenticationException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AuthenticationService implements AuthenticationServiceInterface, OauthUserRepositoryInterface
{
    /**
     * @var CsrfTokenManagerInterface
     */
    private $csrfTokenManager;

    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var HttpFoundationFactoryInterface
     */
    private $symfonyRequestFactory;

    /**
     * @var SessionInterface
     */
    private $session;

    /**
     * @var UserRepositoryInterface
     */
    private $userRepository;

    /**
     * @var UserPasswordEncoderInterface
     */
    private $userPasswordEncoder;

    public function __construct(
        CsrfTokenManagerInterface $csrfTokenManager,
        TokenStorageInterface $tokenStorage,
        HttpFoundationFactoryInterface $symfonyRequestFactory,
        SessionInterface $session,
        UserRepositoryInterface $userRepository,
        UserPasswordEncoderInterface $userPasswordEncoder
    ) {
        $this->csrfTokenManager = $csrfTokenManager;
        $this->tokenStorage = $tokenStorage;
        $this->symfonyRequestFactory = $symfonyRequestFactory;
        $this->session = $session;
        $this->userRepository = $userRepository;
        $this->userPasswordEncoder = $userPasswordEncoder;
    }

    public function isCsrfTokenValid(string $id, string $token): bool
    {
        return $this->csrfTokenManager->isTokenValid(new CsrfToken($id, $token));
    }

    public function getLoggedInUserId(): UserId
    {
        return $this->getSecurityUser()->getUserId();
    }

    public function getLoggedInUser(): User
    {
        return $this->userRepository->findOneById($this->getLoggedInUserId());
    }

    public function getLastAuthenticationError(
        ServerRequestInterface $request,
        bool $clearSession = true
    ): ?AuthenticationException {
        $request = $this->symfonyRequestFactory->createRequest($request);
        $session = $this->session;
        /** @var SymfonyAuthenticationException|null $symfonyAuthenticationException */
        $symfonyAuthenticationException = null;

        if ($request->attributes->has(Security::AUTHENTICATION_ERROR)) {
            $symfonyAuthenticationException = $request->attributes->get(Security::AUTHENTICATION_ERROR);
        } elseif ($session !== null && $session->has(Security::AUTHENTICATION_ERROR)) {
            $symfonyAuthenticationException = $session->get(Security::AUTHENTICATION_ERROR);

            if ($clearSession) {
                $session->remove(Security::AUTHENTICATION_ERROR);
            }
        }

        return $symfonyAuthenticationException
            ? new AuthenticationException(
                $symfonyAuthenticationException->getMessage(),
                $symfonyAuthenticationException->getCode(),
                $symfonyAuthenticationException,
                $symfonyAuthenticationException->getMessageKey(),
                $symfonyAuthenticationException->getMessageData()
            )
            : null;
    }

    public function getLastAuthenticationUsername(ServerRequestInterface $request): string
    {
        $request = $this->symfonyRequestFactory->createRequest($request);
        if ($request->attributes->has(Security::LAST_USERNAME)) {
            return $request->attributes->get(Security::LAST_USERNAME, '');
        }

        return $this->session === null ? '' : $this->session->get(Security::LAST_USERNAME, '');
    }

    /**
     * @param string $username
     * @param string $password
     * @param string $grantType
     */
    public function getUserEntityByUserCredentials(
        $username,
        $password,
        $grantType,
        ClientEntityInterface $clientEntity
    ): ?UserEntityInterface {
        $user = $this->userRepository->findOneByEmail($username);
        if ($user === null) {
            return null;
        }

        $securityUser = SecurityUser::fromUser($user);

        if (!$this->userPasswordEncoder->isPasswordValid($securityUser, $password)) {
            return null;
        }

        return $securityUser;
    }

    private function getSecurityUser(): SecurityUser
    {
        $token = $this->tokenStorage->getToken();

        if ($token === null) {
            throw new NoUserAuthenticatedException();
        }

        $securityUser = $token->getUser();
        if (!$securityUser instanceof SecurityUser) {
            throw new NoUserAuthenticatedException();
        }

        return $securityUser;
    }
}

<?php

namespace WEEEOpen\Tarallo\HTTP;

use Jumbojett\OpenIDConnectClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use WEEEOpen\Tarallo\Database\Database;
use WEEEOpen\Tarallo\SessionSSO;
use WEEEOpen\Tarallo\User;
use Zend\Diactoros\Response\RedirectResponse;

class AuthManager implements MiddlewareInterface {
	const COOKIE_NAME = 'tsessionsso';
	const KEYSPACE = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_';
	const KEYSPACE_STRLEN = 64;
	private $browser = true;

	/**
	 * @param bool $browser The client is a browser that can be redirect to the SSO server
	 */
	public function __construct($browser = true) {
		$this->browser = $browser;
	}

	/**
	 * Set the cookie
	 *
	 * @param $newContent
	 * @param $expire
	 */
	protected static function setCookie(string $newContent, int $expire) {
		setcookie(self::COOKIE_NAME, $newContent, $expire, '', '', !TARALLO_DEVELOPMENT_ENVIRONMENT, true);
	}

	private static function oidc() {
		$oidc = new OpenIDConnectClient(TARALLO_OIDC_ISSUER, TARALLO_OIDC_CLIENT_KEY, TARALLO_OIDC_CLIENT_SECRET);
		$oidc->addScope(['openid', 'profile']);
		return $oidc;
	}

	/**
	 * Create a new session identifier and check that it's unique.
	 * ...it will probably be, but check anyway.
	 *
	 * @param Database $db
	 *
	 * @return string
	 */
	private static function newUniqueIdentifier(Database $db) {
		do {
			$id = self::newIdentifier();
		} while($db->userDAO()->sessionExists($id));

		return $id;
	}

	/**
	 * Create a new session identifier.
	 *
	 * @return string
	 * @see newUniqueIdentifier
	 */
	private static function newIdentifier() {
		$str = '';
		for($i = 0; $i < 32; $i++) {
			try {
				$str .= self::KEYSPACE[random_int(0, self::KEYSPACE_STRLEN - 1)];
			} catch(\Exception $e) {
				// Okay PHPStorm, will you stop complaining now? Please?
				echo 'Not enough entropy';
				exit(1);
			}
		}

		return $str;
	}

	/**
	 * Is the request within the grace time limits, if ID token has expired?
	 *
	 * @param ServerRequestInterface $request The request
	 * @param int $expiry ID token expiry
	 *
	 * @return bool True if it should be graced, false if it shouldn't
	 */
	private static function withinGrace(ServerRequestInterface $request, int $expiry): bool {
		if(time() + TARALLO_POST_GRACE_TIME < $expiry) {
			return false;
		}
		$method = $request->getMethod();
		if($method !== 'POST' && $method !== 'PUT' && $method !== 'PATCH') {
			return false;
		}
		return true;
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		$path = $request->getUri()->getPath();
		// These paths are in the SSR thing, not
		if($path === '/auth') {
			return $this->handleAuthResponse($request, $handler);
		} else if($path === '/logout') {
			return $this->terminate($request, $handler);
		}

		$cookie = $request->getCookieParams();
		/** @var Database $db */
		$db = $request->getAttribute('Database');

		if(isset($cookie[self::COOKIE_NAME])) {
			$id = $cookie[self::COOKIE_NAME];
			$session = $db->userDAO()->getSession($id);

			if($session === null) {
				// Failed login or very old expired session or some kind of attack, delete the cookie
				self::setCookie($id, 1);
				$db->beginTransaction();
				$db->userDAO()->deleteSession($id);
				$db->commit();

				$session = null;
				$user = null;
			} else if(time() < $session->idTokenExpiry || self::withinGrace($request, $session->idTokenExpiry)) {
				// We're good to go, the sessions is valid (or within grace time)
				$user = User::fromSession($session);
			} else if(time() < $session->refreshTokenExpiry) {
				// Ok, ID Token expired, but Refresh Token is still valid
				// TODO: perform refresh somewhere
				// Until this is implemented, discard the refresh token and begin a new session
				self::setCookie($id, 1);
				$db->beginTransaction();
				$db->userDAO()->deleteSession($id);
				$db->commit();

				// Right now, we have nothing
				$session = null;
				$user = null;
			} else {
				// Everything expired, delete the old session and begin a new one
				self::setCookie($id, 1);
				$db->beginTransaction();
				$db->userDAO()->deleteSession($id);
				$db->commit();

				// Right now, we have nothing
				$session = null;
				$user = null;
			}
		} else {
			// No cookie
			$session = null;
			$user = null;
		}

		try {
			$response = $handler->handle($request->withAttribute('User', $user));
		} catch(AuthenticationException $e) {
			if(!$this->browser) {
				throw $e;
			}

			// We need to authenticate.
			// TODO: support refresh

			// Create new session
			$id = self::newUniqueIdentifier($db);

			$db->beginTransaction();
			// Delete previous data (if any), ensure that session exists, lock the database row (useless)
			$db->userDAO()->setDataForSession($id, null);
			// After login, go back there
			$db->userDAO()->setRedirectForSession($id, $request->getUri());
			$db->commit();

			// Enough time to log in
			self::setCookie($id, time() + 600);

			// Done, see you at /auth!
			if(TARALLO_DEVELOPMENT_ENVIRONMENT) {
				error_log('DEV: Bypassing authentication step 1');

				http_response_code(303);
				header("Location: /auth");
			} else {
				$oidc = self::oidc();
				$gohere = 'https://' . $request->getUri()->getHost() . '/auth';

				$oidc->setRedirectURL($gohere);
				$oidc->authenticate();
			}
			exit;
		}
		return $response;
	}

	private function handleAuthResponse(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler
	): ResponseInterface {
		// Coming back from a trip to the SSO...

		// ...or is it? Let's check
		$cookie = $request->getCookieParams();
		/** @var Database $db */
		$db = $request->getAttribute('Database');
		if(isset($cookie[self::COOKIE_NAME])) {
			$id = $cookie[self::COOKIE_NAME];
			$redirect = $db->userDAO()->getRedirect($id);

			// TODO: also check the SSO query parameters
			if($redirect === null) {
				// Nowhere to go, probably something is missing
				$request = $request->withAttribute('User', null);
			} else {
				// We have everything! Probably!
				if(TARALLO_DEVELOPMENT_ENVIRONMENT) {
					error_log('DEV: Bypassing authentication step 2');

					$session = new SessionSSO();
					$session->uid = 'dev.user';
					$session->cn = 'Developement User';
					$session->idToken = 'F00B4R';
					$session->idTokenExpiry = time() + 60 * 60 * 24;
					$session->refreshToken = 'N0REFRESH';
					$session->refreshTokenExpiry = 0;
				} else {
					$oidc = self::oidc();
					$oidc->authenticate();

					$session = new SessionSSO();
					$session->uid = $oidc->getVerifiedClaims('preferred_username');
					$session->cn = $oidc->getVerifiedClaims('name');
					// $user->groups = $oidc->getVerifiedClaims('groups');
					$session->idToken = $oidc->getIdToken();
					$session->idTokenExpiry = $oidc->getVerifiedClaims('exp');
					$session->refreshToken = $oidc->getRefreshToken();
					// TODO: this is apparently not stated in the token, use a constant
					$session->refreshTokenExpiry = time() + TARALLO_OIDC_REFRESH_TOKEN_EXPIRY;

					// Update the cookie
					self::setCookie($id, $session->idTokenExpiry);
				}

				// Store it!
				$db->beginTransaction();
				$db->userDAO()->setDataForSession($id, $session);
				$db->userDAO()->setRedirectForSession($id, $request->getUri());
				$db->commit();
				//$request = $request->withAttribute('User', User::fromSession($session));

				// Do not process further middleware, just redirect
				return new RedirectResponse($redirect, 303);
			}
		} else {
			// uh, cookie is missing... no user here
			$request = $request->withAttribute('User', null);
		}

		return $handler->handle($request);
	}

	private function terminate(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler
	): ResponseInterface {
		// TODO: perform SLO
		//return new RedirectResponse('/logout/result', 303);

		return $handler->handle($request);
	}
}
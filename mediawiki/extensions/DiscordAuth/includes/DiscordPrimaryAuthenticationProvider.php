<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Auth\AbstractPrimaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Extension\CentralAuth\CentralAuthPrimaryAuthenticationProvider;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Password\InvalidPassword;
use MediaWiki\User\UserFactory;
use RuntimeException;
use StatusValue;
use Wikimedia\Rdbms\IDBAccessObject;

class DiscordPrimaryAuthenticationProvider extends AbstractPrimaryAuthenticationProvider {
	private const CREATION_IDENTITY = 'DiscordAuth.creationIdentity';

	public function __construct(
		private DiscordClient $client, private LinkStore $links, private UserFactory $users,
		private AuthorizationStore $authorizations
	) {
	}

	public function getAuthenticationRequests( $action, array $options ) {
		if ( !$this->client->isEnabled() ) {
			return [];
		}
		if ( in_array( $action, [ AuthManager::ACTION_LOGIN, AuthManager::ACTION_CREATE,
			AuthManager::ACTION_LINK ], true ) ) {
			return [ new DiscordAuthenticationRequest() ];
		}
		if ( $action === AuthManager::ACTION_REMOVE && !empty( $options['username'] ) ) {
			$central = CentralAuthUser::getPrimaryInstanceByName( $options['username'] );
			$id = $central->isAttached() ? $this->links->discordId( $central->getId() ) : null;
			return $id === null ? [] : [ new RemoveDiscordRequest( $id ) ];
		}
		return [];
	}

	public function accountCreationType() {
		return self::TYPE_LINK;
	}

	public function providerNormalizeUsername( $username ) {
		return null;
	}

	public function testUserExists( $username, $flags = IDBAccessObject::READ_NORMAL ) {
		// CentralAuth alone owns account existence and username reservation.
		return false;
	}

	public function testUserCanAuthenticate( $username ) {
		$central = CentralAuthUser::getPrimaryInstanceByName( $username );
		return $central->exists() && $this->links->discordId( $central->getId() ) !== null;
	}

	public function beginPrimaryAuthentication( array $reqs ) {
		return $this->begin( $reqs, 'login' );
	}

	public function continuePrimaryAuthentication( array $reqs ) {
		$identity = $this->complete( $reqs, 'login' );
		if ( $identity instanceof AuthenticationResponse ) {
			return $identity;
		}
		return $this->authenticateIdentity( $identity );
	}

	private function authenticateIdentity( DiscordIdentityRequest $identity ): AuthenticationResponse {
		$id = $this->links->globalId( $identity->discordId );
		if ( $id === null ) {
			$response = AuthenticationResponse::newPass();
			$response->createRequest = $identity;
			$response->linkRequest = $identity;
			return $response;
		}
		$central = CentralAuthUser::newPrimaryInstanceFromId( $id );
		if ( !$central || !$central->exists() || $central->isLocked() || $central->isHidden() ) {
			return $this->failure( 'discordauth-failed' );
		}
		$local = $this->users->newFromName( $central->getName() );
		// Never log into a local namesake which is not attached to this global account.
		if ( ( $local->isRegistered() && !$central->isAttached() )
			|| ( !$local->isRegistered() && $central->listUnattached() )
		) {
			return $this->failure( 'discordauth-failed' );
		}
		// AuthManager runs autocreation, CentralAuth lock/rename checks and OATHAuth next.
		return AuthenticationResponse::newPass( $central->getName() );
	}

	public function beginPrimaryAccountCreation( $user, $creator, array $reqs ) {
		$identity = AuthenticationRequest::getRequestByClass( $reqs, DiscordIdentityRequest::class );
		if ( $identity ) {
			return $this->create( $user, $identity );
		}
		return $this->begin( $reqs, 'create', $user->getName() );
	}

	public function continuePrimaryAccountCreation( $user, $creator, array $reqs ) {
		$identity = $this->complete( $reqs, 'create', $user->getName() );
		return $identity instanceof AuthenticationResponse ? $identity : $this->create( $user, $identity );
	}

	private function create( $user, DiscordIdentityRequest $identity ): AuthenticationResponse {
		if ( !$identity->isFresh() ) {
			return $this->failure( 'discordauth-expired' );
		}
		if ( $this->links->globalId( $identity->discordId ) !== null ) {
			return $this->failure( 'discordauth-already-linked' );
		}
		$central = CentralAuthUser::getPrimaryInstance( $user );
		if ( $central->exists() || $central->listUnattached() ) {
			return $this->failure( 'userexists' );
		}
		// Keep email tied to the OAuth identity even if a caller submits an email parameter.
		$user->setEmail( $identity->email );
		$emailTimestamp = $identity->emailVerified && $identity->email !== '' ? wfTimestampNow() : null;
		$user->setEmailAuthenticationTimestamp( $emailTimestamp );
		// AuthManager has already run every provider's creation/permission/AntiSpoof checks.
		// null creates an InvalidPassword, so there is no unknown random password to recover.
		if ( !$central->register( null, $user->getEmail() ) ) {
			return $this->failure( 'userexists' );
		}
		if ( $emailTimestamp !== null ) {
			$central->setEmailAuthenticationTimestamp( $emailTimestamp );
			$central->saveSettings();
		}
		$this->manager->setAuthenticationSessionData( self::CREATION_IDENTITY, $identity );
		return AuthenticationResponse::newPass( $user->getName() );
	}

	public function finishAccountCreation( $user, $creator, AuthenticationResponse $response ) {
		$identity = $this->manager->getAuthenticationSessionData( self::CREATION_IDENTITY );
		$this->manager->removeAuthenticationSessionData( self::CREATION_IDENTITY );
		if ( !$identity instanceof DiscordIdentityRequest ) {
			throw new RuntimeException( 'Missing verified Discord identity during account creation' );
		}
		// Reuse CentralAuth's attachment and cross-wiki account creation jobs.
		$this->manager->getAuthenticationProvider( CentralAuthPrimaryAuthenticationProvider::class )
			->finishAccountCreation( $user, $creator, $response );
		$this->links->link( $identity->discordId, CentralAuthUser::getPrimaryInstance( $user )->getId() );
		return null;
	}

	public function beginPrimaryAccountLink( $user, array $reqs ) {
		return $this->begin( $reqs, 'link', $user->getName() );
	}

	public function continuePrimaryAccountLink( $user, array $reqs ) {
		$identity = $this->complete( $reqs, 'link', $user->getName() );
		if ( $identity instanceof AuthenticationResponse ) {
			return $identity;
		}
		$identity->username = $user->getName();
		$identity->action = AuthManager::ACTION_CHANGE;
		$status = $this->providerAllowsAuthenticationDataChange( $identity );
		if ( !$status->isGood() ) {
			return $this->failure( 'discordauth-already-linked' );
		}
		$this->providerChangeAuthenticationData( $identity );
		return AuthenticationResponse::newPass( $user->getName() );
	}

	public function providerAllowsAuthenticationDataChange( AuthenticationRequest $req, $checkData = true ) {
		if ( !$req instanceof DiscordIdentityRequest && !$req instanceof RemoveDiscordRequest ) {
			return StatusValue::newGood( 'ignored' );
		}
		if ( !$checkData ) {
			return StatusValue::newGood();
		}
		$central = CentralAuthUser::getPrimaryInstanceByName( (string)$req->username );
		if ( !$central->exists() || !$central->isAttached() || $central->isLocked() ) {
			return StatusValue::newFatal( 'discordauth-failed' );
		}
		if ( $req instanceof RemoveDiscordRequest ) {
			if ( $req->action !== AuthManager::ACTION_REMOVE ) {
				return StatusValue::newFatal( 'discordauth-failed' );
			}
			// A Discord-only account must set a password before removing its only login method.
			return $central->getPasswordObject() instanceof InvalidPassword
				? StatusValue::newFatal( 'discordauth-set-password' ) : StatusValue::newGood();
		}
		if ( !$req->isFresh() || $req->action !== AuthManager::ACTION_CHANGE ) {
			return StatusValue::newFatal( 'discordauth-expired' );
		}
		$existingGlobal = $this->links->globalId( $req->discordId );
		$existingDiscord = $this->links->discordId( $central->getId() );
		if ( ( $existingGlobal !== null && $existingGlobal !== $central->getId() )
			|| ( $existingDiscord !== null && $existingDiscord !== $req->discordId )
		) {
			return StatusValue::newFatal( 'discordauth-already-linked' );
		}
		return StatusValue::newGood();
	}

	public function providerChangeAuthenticationData( AuthenticationRequest $req ) {
		if ( $req instanceof DiscordIdentityRequest || $req instanceof RemoveDiscordRequest ) {
			$status = $this->providerAllowsAuthenticationDataChange( $req );
			if ( !$status->isGood() ) {
				throw new RuntimeException( 'Discord credential change rejected' );
			}
			$id = CentralAuthUser::getPrimaryInstanceByName( $req->username )->getId();
			if ( $req instanceof RemoveDiscordRequest ) {
				$this->links->unlink( $req->discordId, $id );
			} else {
				$this->links->link( $req->discordId, $id );
			}
		}
	}

	public function providerRevokeAccessForUser( $username ) {
		$central = CentralAuthUser::getPrimaryInstanceByName( $username );
		$id = $central->exists() ? $this->links->discordId( $central->getId() ) : null;
		if ( $id !== null ) {
			$this->links->unlink( $id, $central->getId() );
		}
	}

	private function begin( array $reqs, string $action, string $target = '' ): AuthenticationResponse {
		$button = AuthenticationRequest::getRequestByClass( $reqs, DiscordAuthenticationRequest::class );
		if ( !$button || empty( $button->discordLogin ) ) {
			return AuthenticationResponse::newAbstain();
		}
		$resume = $this->manager->getRequest()->getText( 'discordResume' );
		if ( $action === 'login' && $resume !== '' ) {
			$identity = $this->takeVerified( $resume, $action, $target );
			return $identity instanceof AuthenticationResponse ? $identity : $this->authenticateIdentity( $identity );
		}
		$returnUrl = OAuthFlow::continuationUrl( (string)$button->returnToUrl,
			$this->config->get( 'Server' ), $this->config->get( 'CanonicalServer' ),
			$this->client->callbackUrl() );
		if ( !$this->client->isEnabled() || $returnUrl === null ) {
			return $this->failure( 'discordauth-failed' );
		}
		$state = $this->authorizations->start( $this->manager->getRequest(), $returnUrl,
			$this->client->callbackUrl(), $action, $target );
		return AuthenticationResponse::newRedirect(
			[ new DiscordCallbackRequest() ], $this->client->authorizationUrl( $state )
		);
	}

	private function complete( array $reqs, string $action, string $target = '' ):
		DiscordIdentityRequest|AuthenticationResponse
	{
		$req = AuthenticationRequest::getRequestByClass( $reqs, DiscordCallbackRequest::class );
		return $this->takeVerified( (string)( $req->state ?? '' ), $action, $target );
	}

	private function takeVerified( string $state, string $action, string $target ):
		DiscordIdentityRequest|AuthenticationResponse
	{
		$verified = $this->authorizations->consume( $this->manager->getRequest(), $state,
			$this->client->callbackUrl(), 'identity' );
		if ( !$verified || $verified['action'] !== $action || $verified['target'] !== $target
			|| !is_array( $verified['identity'] ?? null )
		) {
			return $this->failure( 'discordauth-expired' );
		}
		$data = $verified['identity'];
		$identity = new DiscordIdentityRequest( $data['id'], $data['label'], $data['verifiedAt'],
			$data['email'], $data['emailVerified'] );
		return $identity->isFresh() ? $identity : $this->failure( 'discordauth-expired' );
	}

	private function failure( string $message ): AuthenticationResponse {
		return AuthenticationResponse::newFail( wfMessage( $message ) );
	}
}

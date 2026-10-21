<?php

namespace RatePage;

use IContextSource;
use User;

class Rights {
	public static function getAllGroups( IContextSource $context ) : array {
		$groups = $context->getConfig()->get( 'GroupPermissions' );

		return array_keys( $groups );
	}

	public static function getGroupsAsColumns( IContextSource $context ) : array {
		$groups = self::getAllGroups( $context );
		$res = [];

		foreach ( $groups as $group ) {
			$res[$context->msg( "group-$group" )->escaped()] = $group;
		}

		return $res;
	}

	public static function checkUserCanExecute( $allowed, User $user ) : bool {
		$groups = explode( ',', $allowed );

		return (bool) sizeof( array_intersect( $groups, $user->getEffectiveGroups() ) );
	}

	/**
	 * @param $contest
	 * @param User $user
	 *
	 * @return bool[]
	 */
	public static function checkUserPermissionsOnContest( $contest, User $user ) : array {
		$eg = $user->getEffectiveGroups();

		if ( !$contest ) {
			return [
				'vote' => false,
				'see' => false
			];
		}

		return [
			'vote' => (
				(bool) sizeof( array_intersect( explode( ',', $contest->rpc_allowed_to_vote ), $eg ) ) &&
				( (bool) $contest->rpc_enabled )
			),
			'see' => (bool) sizeof( array_intersect( explode( ',', $contest->rpc_allowed_to_see ), $eg ) )
		];
	}
}

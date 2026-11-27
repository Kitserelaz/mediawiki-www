<?php

use Wikimedia\ParamValidator\ParamValidator;

class CommentSubmitAPI extends CommentSubmitBase {

	public function execute() {
		$main = $this->getMain();
		$user = $this->getUser();

		$pageID = $main->getVal( 'pageID' );
		$commentText = $main->getVal( 'commentText' );

		// throws error on failure to validate
		$this->checkBlocks( $user );
		$this->validateCommentText( $commentText, $user, $pageID );

		$page = new CommentsPage( $pageID, $this->getContext() );

		Comment::add( $commentText, $page, $user, $main->getVal( 'parentID' ) );

		if ( class_exists( 'UserStatsTrack' ) ) {
			$stats = new UserStatsTrack( $user->getId(), $user->getName() );
			$stats->incStatField( 'comment' );
		}

		$result = $this->getResult();
		$result->addValue( $this->getModuleName(), 'ok', 'ok' );

		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'pageID' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'integer'
			],
			'parentID' => [
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_TYPE => 'integer'
			],
			'commentText' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string'
			]
		];
	}
}

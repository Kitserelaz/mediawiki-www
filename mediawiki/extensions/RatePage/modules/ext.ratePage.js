/**
 * ratePage stars
 * tested on minerva, timeless, vector and monobook
 **/
mw.RatePage = function () {
	var self = {};

	self.maxRating = 5;

	self.starMap = {};

	/**
	 * Rate a page.
	 * @param pageId
	 * @param contest
	 * @param answer
	 * @param starMap
	 */
	self.ratePage = function ( pageId, contest, answer, starMap ) {
		( new mw.Api() ).postWithEditToken( {
			action: 'ratepage',
			format: 'json',
			pageid: pageId,
			contest: contest,
			answer: answer,
		} ).done( function ( data ) {
				if ( !data.userVote || data.userVote === -1 ) {
					mw.notify( mw.message( 'ratePage-vote-error' ).text(), {type: 'error'} );
					return;
				}

				var voteCount = null, avg = null;

				if ( data.pageRating ) {
					voteCount = 0;
					for ( var i = 1; i <= self.maxRating; i++ ) voteCount += ( data.pageRating[i] );
					avg = 0;
					for ( i = 1; i <= self.maxRating; i++ ) avg += ( data.pageRating[i] * i );
					avg = avg / voteCount;
				}
				
				var isContest = !!contest;

				starMap[contest][pageId].forEach( function ( widget ) {
					self.updateStars(
						avg,
						voteCount,
						data.userVote,
						data.canVote,
						data.canSee,
						data.showResultsBeforeVoting,
						false,
						isContest,
						widget,
						starMap
					);
				} );
			} );
	};

	/**
	 * Get ratings for a bunch of pages at once.
	 * @param idToWidgetMap
	 * @param contest
	 * @param starMap
	 */
	self.getRating = function ( idToWidgetMap, contest, starMap ) {
		var pageids = Object.keys( idToWidgetMap );
		const pageidsLimit = 50;   // MediaWiki supports up to 50 pageIds at a time
		var pageidsStart = 0;
		while (pageidsStart < pageids.length) {
			var pageidsSubset = pageids.slice(pageidsStart, pageidsStart+pageidsLimit);
			pageidsStart += pageidsLimit;
			( new mw.Api() ).post( {
				action: 'query',
				prop: 'pagerating',
				format: 'json',
				prcontest: contest,
				pageids: pageidsSubset
			} )
				.done( function ( data ) {
					Object.keys( data.query.pages ).forEach( function ( pageid ) {
						var voteCount = null, avg = null;
						var d = data.query.pages[pageid].pagerating;

						if ( !d ) {
							return;
						}
						if ( d.pageRating ) {
							voteCount = 0;
							for ( var i = 1; i <= self.maxRating; i++ ) voteCount += ( d.pageRating[i] );
							avg = 0;
							for ( i = 1; i <= self.maxRating; i++ ) avg += ( d.pageRating[i] * i );
							avg = avg / voteCount;
						}

						var isContest = !!contest;

						idToWidgetMap[pageid].forEach( function ( widget ) {
							self.updateStars(
								avg,
								voteCount,
								d.userVote,
								d.canVote,
								d.canSee,
								d.showResultsBeforeVoting,
								true,
								isContest,
								widget,
								starMap
							);
						} );
					} );
				} );
			}
	};

	/**
	 * Update the rating widget.
	 * @param average
	 * @param vCount
	 * @param userVote
	 * @param canVote
	 * @param canSee
	 * @param showResultsBeforeVoting
	 * @param isNew
	 * @param isContest
	 * @param parent
	 * @param starMap
	 */
	self.updateStars = function ( average, vCount, userVote, canVote, canSee, showResultsBeforeVoting, isNew, isContest, parent, starMap ) {
		function typeForLastStar( f2 ) {
			if ( f2 < 0.05 ) {
				return 'ratingstar-plain';
			} else if ( f2 < 0.4 ) {
				return 'ratingstar-1-4';
			} else if ( f2 < 0.65 ) {
				return 'ratingstar-2-4';
			} else {
				return 'ratingstar-3-4';
			}
		}

		if ( !parent.attr( 'data-page-id' ) ) {
			parent = parent.parent();
		}

		var yourVote = '';

		if ( canVote ) {
			if ( showResultsBeforeVoting || !canSee ) {
				yourVote = mw.message( 'ratePage-prompt-can-see' ).text();
			} else {
				yourVote = mw.message( 'ratePage-prompt' ).text();
			}
		} else {
			if ( isContest ) {
				yourVote = mw.message( 'ratePage-vote-cannot-vote' ).text();
			} else {
				yourVote = mw.message( 'ratePage-page-cannot-vote' ).text();
			}
		}

		if ( ( userVote && userVote !== -1 ) ||
			( !canVote && canSee ) ||
			( showResultsBeforeVoting && canSee )
		) {
			if ( userVote && userVote !== -1 ) {
				yourVote = mw.message( 'ratePage-vote-info', userVote.toString() ).text();
			}

			if ( !average ) {
				if ( canSee ) {
					parent.find( '.ratingsinfo-avg' ).text( "" );
				} else {
					parent.find( '.ratingsinfo-avg' ).text( mw.message( 'ratePage-vote-cannot-see' ) );
				}

				for ( var i = 1; i <= self.maxRating; i++ ) {
					if ( i <= userVote ) {
						parent.find( '.ratingstar[data-ratingstar-no="' + i.toString() + '"]' )
							.removeClass( "ratingstar-plain ratingstar-1-4 ratingstar-2-4 ratingstar-3-4 ratingstar-full" )
							.addClass( "ratingstar-full" );
					} else {
						parent.find( '.ratingstar[data-ratingstar-no="' + i.toString() + '"]' )
							.removeClass( "ratingstar-1-4 ratingstar-2-4 ratingstar-3-4 ratingstar-full" )
							.addClass( "ratingstar-plain" );
					}
				}
			} else {
				parent.find( '.ratingsinfo-avg' ).text( mw.message( 'ratePage-vote-average-info', average.toFixed( 2 ), vCount.toString() ).text() );

				var f1 = parseInt( average.toFixed( 1 ).slice( 0, -1 ).replace( '.', '' ) );
				for ( i = 1; i <= self.maxRating; i++ ) {
					if ( i <= f1 ) {
						parent.find( '.ratingstar[data-ratingstar-no="' + i.toString() + '"]' )
							.removeClass( "ratingstar-plain ratingstar-1-4 ratingstar-2-4 ratingstar-3-4 ratingstar-full" )
							.addClass( "ratingstar-full" );
					} else if ( i === f1 + 1 ) {
						parent.find( '.ratingstar[data-ratingstar-no="' + i.toString() + '"]' )
							.removeClass( "ratingstar-plain ratingstar-1-4 ratingstar-2-4 ratingstar-3-4 ratingstar-full" )
							.addClass( typeForLastStar( average - f1 ) );
					} else {
						parent.find( '.ratingstar[data-ratingstar-no="' + i.toString() + '"]' )
							.removeClass( "ratingstar-1-4 ratingstar-2-4 ratingstar-3-4 ratingstar-full" )
							.addClass( "ratingstar-plain" );
					}
				}
			}
		}

		parent.find( '.ratingsinfo-yourvote' ).text( yourVote );

		if ( isNew && canVote ) {
			parent.find( '.ratingstar' ).addClass( 'canvote' );

			/* add behavior to the stars */
			var stars = parent.find( '.ratingstar' );
			stars.click( function () {
				var answer = $( this ).attr( 'data-ratingstar-no' );
				var p = $( this ).parent();
				if ( !p.attr( 'data-page-id' ) ) {
					p = p.parent();
				}

				var pageId = p.attr( 'data-page-id' );

				if ( !pageId ) {
					// It's the main rating widget
					self.ratePage( mw.config.get( 'wgArticleId' ), '', answer, starMap );
				} else {
					self.ratePage( pageId, p.attr( 'data-contest' ), answer, starMap );
				}
			} );

			if ( mw.config.get( 'skin' ) !== "minerva" ) {
				stars.mouseover( function () {
					var no = $( this ).attr( 'data-ratingstar-no' );
					for ( var i = 1; i <= no; i++ ) {
						$( this ).siblings( '.ratingstar[data-ratingstar-no="' + i.toString() + '"]' ).addBack()
							.addClass( 'ratingstar-mousedown' );
					}
				} ).mouseout( function () {
					$( this ).siblings( '.ratingstar' ).addBack().removeClass( 'ratingstar-mousedown' );
				} );
			}
		}
	};

	/**
	 * Adds an entry to the starMap.
	 * @param contest
	 * @param pageId
	 * @param stars
	 * @param starMap The starMap to append to. Defaults to the main map.
	 */
	self.addToStarMap = function ( contest, pageId, stars, starMap ) {
		starMap = starMap || self.starMap;

		if ( !starMap[contest] ) starMap[contest] = {};
		if ( !starMap[contest][pageId] ) starMap[contest][pageId] = [];

		starMap[contest][pageId].push( stars );
	}

	/**
	 * Retrieves rating information about all widgets, in batches.
	 * @param starMap Optional, the starMap to submit, if not the main one.
	 */
	self.submitStarMap = function ( starMap ) {
		starMap = starMap || self.starMap;
		Object.keys( starMap ).forEach( function ( contest ) {
			self.getRating( starMap[contest], contest, starMap );
		} );
	}

	/**
	 * Initialize an embedded widget.
	 * @param stars
	 * @param starMap Optional, the starMap to add the widget to, if not the main one.
	 */
	self.initializeTag = function ( stars, starMap ) {
		var pageId = stars.attr( 'data-page-id' );
		var contest = stars.attr( 'data-contest' ) || '';
		var starsInner = '<div class="ratingstars-embed">';

		for ( var i = 1; i <= self.maxRating; i++ ) {
			starsInner += '<div class="ratingstar ratingstar-embed ratingstar-plain"';
			if ( self.maxRating === 5 ) {
				starsInner += 'title="' + mw.message( 'ratePage-caption-' + i.toString() ).text() + '"';
			}
			starsInner += 'data-ratingstar-no="' + i.toString() + '"></div>'
		}
		stars.append( starsInner );
		stars.append( '<div class="ratingsinfo-embed"><div class="ratingsinfo-yourvote"></div><div class="ratingsinfo-avg"></div></div>' );

		self.addToStarMap( contest, pageId, stars, starMap );
	};

	/**
	 * Initialize the sidebar widget and embedded rating widgets.
	 */
	self.initialize = function () {
		// read config
		self.maxRating = mw.config.get( 'wgRPRatingMax' );

		/* process all embedded widgets */
		$( 'div.ratepage-embed' ).each( function () {
			self.initializeTag( $( this ) );
		} );

		/* and now the main rating widget in the sidebar or footer */
		if (
			(
				mw.config.get( 'wgRPRatingAllowedNamespaces' ) == null ||
				mw.config.get( 'wgRPRatingAllowedNamespaces' ).indexOf( mw.config.get( 'wgNamespaceNumber' ) ) !== -1
			) &&
			mw.config.get( 'wgRPRatingPageBlacklist' ).indexOf( mw.config.get( 'wgPageName' ) ) === -1 &&
			mw.config.get( 'wgRevisionId' ) !== 0 ) {

			/* add main rating stars (in sidebar or footer) */
			var stars, footerStars;

			var skin = mw.config.get( 'skin' );
			if (
				( skin === 'minerva' || skin === 'timeless' ||
				mw.config.get( 'wgRPTarget' ) === 'mobile' ) &&
				!$( '.footer-ratingstars' ).length &&
				mw.config.get( 'wgRPFooterRatingStars' )
			) {
				var starHtml = '<div class="post-content footer-element active footer-ratingstars" style="margin-top: 22px"> \
					<h2>' + mw.message( "ratePage-vote-title" ).text() + '</h2> \
					<div class="pageRatingStars">';

				for ( var i = 1; i <= self.maxRating; i++ ) {
					starHtml += '<div class="ratingstar ratingstar-mobile ratingstar-plain" data-ratingstar-no="' +
					i + '"></div>';
				}

				starHtml += '</div> \
					<span class="ratingsinfo-mobile"><span class="ratingsinfo-yourvote"></span>\
					<span class="ratingsinfo-avg"></span></span></div>';

				footerStars = $( starHtml );

				if ( skin === 'minerva' ) {
					$( '.last-modified-bar' ).after( footerStars );
				} else if ( skin === 'timeless' ) {
					var afterContent = $( '#mw-data-after-content' );
					if ( afterContent.length === 1 ) {
						afterContent.prepend( footerStars );
					} else {
						afterContent = $( '<div id="mw-data-after-content"></div>' ).append( footerStars );
						$( '#content-bottom-stuff' ).append( afterContent );
					}

				}
			}
			if ( !$( '#ratingstars' ).length ) {
				// for timeless
				$( '#p-ratePage-vote-title' ).removeClass( "emptyPortlet" );
				stars = $( '<div id="ratingstars"></div>' );
				$( '#p-ratePage-vote-title > div' ).append( stars );

				for ( var i = 1; i <= self.maxRating; i++ ) {
					var star = '<div class="ratingstar ratingstar-desktop ratingstar-plain"';
					if ( self.maxRating === 5 ) {
						star += 'title="' + mw.message( 'ratePage-caption-' + i.toString() ).text() + '"';
					}
					star += 'data-ratingstar-no="' + i.toString() +	'"></div>'

					stars.append( star );
				}
				stars.after( '<div class="ratingsinfo-desktop"><div class="ratingsinfo-yourvote"></div><div class="ratingsinfo-avg"></div></div>' );
			}

			if ( stars ) {
				self.addToStarMap( '', mw.config.get( 'wgArticleId' ), stars );
			}

			if ( footerStars ) {
				self.addToStarMap( '', mw.config.get( 'wgArticleId' ), footerStars );
			}
		}

		self.submitStarMap();
	};

	return self;
}();

mw.hook( 'wikipage.content' ).add( function () {
	mw.RatePage.initialize();
} );

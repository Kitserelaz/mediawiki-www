<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 * @author Paladox
 * @author Universal Omega
 * @version 2.0
 */

use GenerateSitemap;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

class GenerateMirahezeSitemap extends Maintenance {

    public function __construct() {
        parent::__construct();

        $this->addDescription( 'Generates sitemap for all Miraheze wikis (apart from private ones).' );
    }

    public function execute() {
        global $IP;

        $dbname = $this->getConfig()->get( MainConfigNames::DBname );
        $remoteWiki = $this->getServiceContainer()->get( 'RemoteWikiFactory' )->newInstance( $dbname );

        \WikiOasis\WikiOasisMagic\Sitemaps::purge( $dbname );
        if ( $remoteWiki->isPrivate() ) {
            $this->output( "Deleted sitemaps for private wiki {$dbname}\n" );
            return;
        }

        $directory = "$IP/sitemaps/$dbname";
        if ( !is_dir( $directory ) && !mkdir( $directory, 0755, true ) ) {
            $this->fatalError( 'Unable to create sitemap directory' );
        }
        if ( realpath( $directory ) !== realpath( $IP ) . "/sitemaps/$dbname" ) {
            $this->fatalError( 'Refusing symlinked sitemap directory' );
        }

        $this->output( "Generating sitemap for wiki {$dbname}\n" );
        $generateSitemap = $this->createChild( GenerateSitemap::class );
        $generateSitemap->setOption( 'fspath', $directory );
        $generateSitemap->setOption( 'urlpath', "/sitemaps/$dbname/" );
        $generateSitemap->setOption( 'server', $this->getConfig()->get( MainConfigNames::Server ) );
        $generateSitemap->setOption( 'compress', 'no' );
        $generateSitemap->setOption( 'skip-redirects', true );
        $generateSitemap->execute();
    }
}

// @codeCoverageIgnoreStart
return GenerateMirahezeSitemap::class;
// @codeCoverageIgnoreEnd


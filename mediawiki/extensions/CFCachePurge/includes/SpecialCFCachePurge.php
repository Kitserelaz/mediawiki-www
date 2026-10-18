<?php

namespace MediaWiki\Extension\CFCachePurge;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use OOUI\ButtonInputWidget;
use SpecialPage;

class SpecialCFCachePurge extends SpecialPage {

    private const RATE_LIMIT_WINDOW = 300;
    private const RATE_LIMIT_MAX_PURGES = 3;

    public function __construct() {
        parent::__construct( 'CFCachePurge', 'cloudflarepurge' );
    }

    public function execute( $subPage ) {
        $this->setHeaders();

        $out = $this->getOutput();
        $request = $this->getRequest();
        $user = $this->getUser();

        if ( !$user->isRegistered() ) {
            throw new \PermissionsError( 'cloudflarepurge' );
        }

        if ( !$this->userCanExecute( $user ) ) {
            throw new \PermissionsError( 'cloudflarepurge' );
        }

        $this->checkReadOnly();

        $out->enableOOUI();
        $rateInfo = $this->getRateLimitInfo();

        $out->addWikiMsg( 'cfcachepurge-intro' );

        if ( !$request->wasPosted() ) {
            $button = new ButtonInputWidget( [
                'type' => 'submit',
                'label' => $this->msg( 'cfcachepurge-submit' )->text(),
                'flags' => [ 'primary', 'progressive' ],
            ] );

            $out->addHTML(
                Html::openElement( 'form', [ 'method' => 'post' ] ) .
                Html::hidden( 'wpEditToken', $user->getEditToken() ) .
                Html::element(
                    'p',
                    [],
                    $this->msg(
                        'cfcachepurge-rate-limit',
                        self::RATE_LIMIT_MAX_PURGES,
                        intdiv( self::RATE_LIMIT_WINDOW, 60 )
                    )->text()
                ) .
                Html::element(
                    'p',
                    [],
                    $this->msg(
                        'cfcachepurge-used',
                        $rateInfo['used'],
                        self::RATE_LIMIT_MAX_PURGES
                    )->text()
                ) .
                Html::element(
                    'p',
                    [],
                    $this->msg( 'cfcachepurge-remaining', $rateInfo['remaining'] )->text()
                ) .
                Html::element(
                    'p',
                    [],
                    $this->msg(
                        'cfcachepurge-next-refresh',
                        $this->formatDuration( $rateInfo['retryAfter'] )
                    )->text()
                ) .
                $button->toString() .
                Html::closeElement( 'form' )
            );
            return;
        }

        if ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
            throw new \ErrorPageError( 'sessionfailure-title', 'sessionfailure' );
        }

        $zoneId = $this->getConfig()->get( 'CFCachePurgeZoneId' );
        $apiToken = $this->getConfig()->get( 'CFCachePurgeApiToken' );

        if ( !$zoneId || !$apiToken ) {
            $out->addHTML(
                Html::element(
                    'div',
                    [ 'class' => 'error' ],
                    $this->msg( 'cfcachepurge-not-configured' )->text()
                )
            );
            return;
        }

        $rateLimit = $this->checkRateLimit();
        if ( !$rateLimit['ok'] ) {
            $out->addHTML(
                Html::element(
                    'div',
                    [ 'class' => 'error' ],
                    $rateLimit['error']
                )
            );

            $rateInfo = $this->getRateLimitInfo();
            $out->addHTML(
                Html::element(
                    'p',
                    [],
                    $this->msg(
                        'cfcachepurge-status-line',
                        $rateInfo['used'],
                        self::RATE_LIMIT_MAX_PURGES,
                        $rateInfo['remaining'],
                        $this->formatDuration( $rateInfo['retryAfter'] )
                    )->text()
                )
            );
            return;
        }

        $result = $this->purgeHost( $zoneId, $apiToken );

        if ( $result['ok'] ) {
            $this->recordPurge();
            $rateInfo = $this->getRateLimitInfo();

            $out->addHTML(
                Html::element(
                    'div',
                    [ 'class' => 'successbox' ],
                    $this->msg( 'cfcachepurge-success', $result['host'] )->text()
                )
            );

            $out->addHTML(
                Html::element(
                    'p',
                    [],
                    $this->msg(
                        'cfcachepurge-status-line',
                        $rateInfo['used'],
                        self::RATE_LIMIT_MAX_PURGES,
                        $rateInfo['remaining'],
                        $this->formatDuration( $rateInfo['retryAfter'] )
                    )->text()
                )
            );
        } else {
            $out->addHTML(
                Html::element(
                    'div',
                    [ 'class' => 'error' ],
                    $this->msg( 'cfcachepurge-failed', $result['error'] )->text()
                )
            );
        }
    }

    private function purgeHost( string $zoneId, string $apiToken ): array {
        $httpRequestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
        $client = $httpRequestFactory->createGuzzleClient();

        $host = parse_url( $this->getConfig()->get( 'Server' ), PHP_URL_HOST );
        if ( !$host ) {
            $host = $this->getRequest()->getHeader( 'Host' );
        }
        if ( !$host ) {
            $host = parse_url( $this->getRequest()->getFullRequestURL(), PHP_URL_HOST );
        }

        if ( !$host ) {
            return [
                'ok' => false,
                'error' => $this->msg( 'cfcachepurge-could-not-determine-host' )->text()
            ];
        }

        $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";

        try {
            $response = $client->request( 'POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . trim( $apiToken ),
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'hosts' => [ $host ]
                ],
                'timeout' => 15,
                'http_errors' => false,
            ] );

            $body = (string)$response->getBody();
            $data = json_decode( $body, true );

            if ( $response->getStatusCode() !== 200 ) {
                return [
                    'ok' => false,
                    'error' => $this->msg(
                        'cfcachepurge-http-error',
                        $response->getStatusCode(),
                        $body
                    )->text(),
                ];
            }

            if ( !is_array( $data ) || empty( $data['success'] ) ) {
                return [
                    'ok' => false,
                    'error' => $body ?: $this->msg( 'cfcachepurge-unknown-api-error' )->text(),
                ];
            }

            return [
                'ok' => true,
                'host' => $host,
            ];
        } catch ( \Throwable $e ) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkRateLimit(): array {
        $rateInfo = $this->getRateLimitInfo();

        if ( $rateInfo['used'] >= self::RATE_LIMIT_MAX_PURGES ) {
            return [
                'ok' => false,
                'error' => $this->msg(
                    'cfcachepurge-rate-limit-exceeded',
                    self::RATE_LIMIT_MAX_PURGES,
                    intdiv( self::RATE_LIMIT_WINDOW, 60 ),
                    $this->formatDuration( $rateInfo['retryAfter'] )
                )->text()
            ];
        }

        return [ 'ok' => true ];
    }

    private function getRateLimitInfo(): array {
        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $key = $cache->makeKey( 'cfcachepurge', 'events' );

        $events = $cache->get( $key );
        if ( !is_array( $events ) ) {
            $events = [];
        }

        $now = time();
        $windowStart = $now - self::RATE_LIMIT_WINDOW;

        $events = array_values( array_filter(
            $events,
            static function ( $timestamp ) use ( $windowStart ) {
                return is_int( $timestamp ) && $timestamp > $windowStart;
            }
        ) );

        $used = count( $events );
        $remaining = max( 0, self::RATE_LIMIT_MAX_PURGES - $used );

        if ( $used === 0 ) {
            $retryAfter = 0;
        } else {
            $oldest = min( $events );
            $retryAfter = max( 0, self::RATE_LIMIT_WINDOW - ( $now - $oldest ) );
        }

        return [
            'used' => $used,
            'remaining' => $remaining,
            'retryAfter' => $retryAfter,
            'events' => $events,
        ];
    }

    private function recordPurge(): void {
        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $key = $cache->makeKey( 'cfcachepurge', 'events' );

        $events = $cache->get( $key );
        if ( !is_array( $events ) ) {
            $events = [];
        }

        $now = time();
        $windowStart = $now - self::RATE_LIMIT_WINDOW;

        $events = array_values( array_filter(
            $events,
            static function ( $timestamp ) use ( $windowStart ) {
                return is_int( $timestamp ) && $timestamp > $windowStart;
            }
        ) );

        $events[] = $now;

        $cache->set( $key, $events, self::RATE_LIMIT_WINDOW );
    }

    private function formatDuration( int $seconds ): string {
        if ( $seconds <= 0 ) {
            return $this->msg( 'cfcachepurge-duration-now' )->text();
        }

        $minutes = intdiv( $seconds, 60 );
        $remainingSeconds = $seconds % 60;

        if ( $minutes > 0 && $remainingSeconds > 0 ) {
            return $this->msg(
                'cfcachepurge-duration-minutes-seconds',
                $minutes,
                $remainingSeconds
            )->text();
        }

        if ( $minutes > 0 ) {
            return $this->msg( 'cfcachepurge-duration-minutes', $minutes )->text();
        }

        return $this->msg( 'cfcachepurge-duration-seconds', $remainingSeconds )->text();
    }

    protected function getGroupName() {
        return 'wiki';
    }
}
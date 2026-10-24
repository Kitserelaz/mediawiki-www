<?php
/** @license GPL-2.0-or-later */
// This CLI-only development fixture never replaces HTTP services in web requests.
$_SERVER['HTTP_HOST'] = 'auth.' . (getenv('MW_DOMAIN') ?: 'localhost');
$_SERVER['REQUEST_URI'] = '/metawiki/wiki/Special:DiscordLogin';
require dirname(__DIR__, 4) . '/maintenance/Maintenance.php';

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\UsernameAuthenticationRequest;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Extension\DiscordAuth\DiscordAuthenticationRequest;
use MediaWiki\Extension\DiscordAuth\DiscordCallbackRequest;
use MediaWiki\Extension\DiscordAuth\DiscordClient;
use MediaWiki\Extension\DiscordAuth\AuthorizationStore;
use MediaWiki\Extension\DiscordAuth\SpecialDiscordCallback;
use MediaWiki\Extension\DiscordAuth\RemoveDiscordRequest;
use MediaWiki\Extension\DiscordAuth\SpecialDiscordLogin;
use MediaWiki\Password\InvalidPassword;
use MediaWiki\Request\FauxRequest;

class ExerciseDiscordAuthentication extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption('exercise', 'Create isolated Discord fixture accounts in the local development farm');
        $this->addOption('resume-browser', 'Resume a development browser fixture using a fake Discord identity');
    }
    private function check(bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
    }
    private function response(AuthenticationResponse $response, string $expected, string $step): void {
        $this->check($response->status === $expected,
            $step . ': ' . $response->status . ' (' . ($response->message?->getKey() ?? '') . ')');
    }
    private function completeCallback($request, $services, $client, string $url): array {
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $state = $params['state'];
        $name = AuthorizationStore::cookieName($state);
        $binding = $request->response()->getCookie($name);
        $this->check(is_string($binding), 'OAuth must set an independent browser binding cookie');
        $request->setCookie($name, $binding, '');
        $request->setVal('state', $state);
        $request->setVal('code', 'fixture-code');
        $page = new SpecialDiscordCallback($client, $services->getService('DiscordAuth.AuthorizationStore'));
        $page->setContext(RequestContext::getMain());
        $page->execute(null);
        parse_str(parse_url(RequestContext::getMain()->getOutput()->getRedirect(), PHP_URL_QUERY), $resume);
        $grant = $resume['state'];
        $request->setCookie(AuthorizationStore::cookieName($grant),
            $request->response()->getCookie(AuthorizationStore::cookieName($grant)), '');
        $this->check($services->getService('DiscordAuth.AuthorizationStore')->consume(
            $request, $state, $client->callbackUrl()) === null, 'Callback replay must be rejected');
        return ['state'=>$grant];
    }
    private function exerciseTransactions($services, $client): void {
        $store = $services->getService('DiscordAuth.AuthorizationStore');
        $request = new FauxRequest([], false, null, 'https');
        $url = 'https://auth.localhost/testwiki/wiki/Special:DiscordLogin';
        $state = $store->start($request, $url, $client->callbackUrl(), 'login', '', true);
        $name = AuthorizationStore::cookieName($state);
        $request->setCookie($name, str_repeat('0', 64), '');
        $this->check($store->consume($request, $state, $client->callbackUrl()) === null,
            'An incorrect browser binding must be rejected without consuming the transaction');
        $request->setCookie($name, $request->response()->getCookie($name), '');
        $db = $services->getService('CentralAuth.CentralAuthDatabaseManager')->getCentralPrimaryDB();
        $db->newUpdateQueryBuilder()->update('discordauth_transaction')->set(['dat_expires'=>time()-1])
            ->where(['dat_state'=>hash('sha256', $state)])->caller(__METHOD__)->execute();
        $this->check($store->consume($request, $state, $client->callbackUrl()) === null,
            'Expired transactions must be rejected');
        $this->check($client->calls === 0, 'Rejected transactions must not call Discord');
    }
    public function execute() {
        $this->check(getenv('MW_ENV') === 'development'
            && ($this->hasOption('exercise') || $this->hasOption('resume-browser'))
            && getenv('MW_DB_HOST') === 'database' && getenv('MW_DOMAIN') === 'localhost',
            'Only the explicitly selected localhost development fixture is allowed');
        $services = $this->getServiceContainer();
        $client = new class($services->getMainConfig(), $services->getHttpRequestFactory()) extends DiscordClient {
            public string $fixtureId;
            public int $calls = 0;
            public function identify(string $code): array {
                $this->calls++;
                if ($code !== 'fixture-code') { throw new RuntimeException('Unexpected fixture code'); }
                return ['id'=>$this->fixtureId, 'label'=>'discord-fixture', 'email'=>'discord-fixture@example.invalid', 'emailVerified'=>true];
            }
        };
        $client->fixtureId = '999' . random_int(100000000000000, 999999999999999);
        $services->redefineService('DiscordAuth.Client', static fn() => $client);
        if ($this->hasOption('resume-browser')) {
            $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            // Simulate Discord only; run the real callback with a completely new wiki session.
            $session = $services->getSessionManager()->getEmptySession();
            $request = new FauxRequest(['state'=>$input['state'], 'code'=>'fixture-code'], false, $session, 'https');
            $request->setCookie(AuthorizationStore::cookieName($input['state']), $input['binding'], '');
            $request->setRequestURL('https://auth.localhost/metawiki/wiki/Special:DiscordCallback');
            RequestContext::getMain()->setRequest($request);
            RequestContext::getMain()->setUser($session->getUser());
            $callback = new SpecialDiscordCallback($client, $services->getService('DiscordAuth.AuthorizationStore'));
            $callback->setContext(RequestContext::getMain());
            $callback->execute(null);
            $url = RequestContext::getMain()->getOutput()->getRedirect();
            $this->check($url !== '', 'Callback must redirect to the starting wiki');
            parse_str(parse_url($url, PHP_URL_QUERY), $params);
            $grant = $params['discordResume'];
            $cookie = $request->response()->getCookie(AuthorizationStore::cookieName($grant));
            $this->check(is_string($cookie), 'Callback must issue a browser-bound identity handoff');
            $this->output(json_encode(['returnUrl'=>$url, 'state'=>$grant, 'binding'=>$cookie], JSON_THROW_ON_ERROR) . "\n");
            return;
        }
        $this->exerciseTransactions($services, $client);
        $request = new FauxRequest([], true, null, 'https');
        $returnUrl = 'https://auth.localhost/metawiki/wiki/Special:DiscordLogin/return';
        $request->setRequestURL($returnUrl);
        RequestContext::getMain()->setRequest($request);
        RequestContext::getMain()->setUser($request->getSession()->getUser());
        $manager = $services->getAuthManager();
        $this->check($manager->getRequest() === $request, 'AuthManager must use the fixture browser session');
        $button = new DiscordAuthenticationRequest();
        $button->loadFromSubmission(['discordLogin'=>true]);
        $redirect = $manager->beginAuthentication([$button], $returnUrl);
        $this->response($redirect, AuthenticationResponse::REDIRECT, 'Username-free OAuth start');
        $flow = $this->completeCallback($request, $services, $client, $redirect->redirectTarget);
        $callback = new DiscordCallbackRequest();
        $callback->loadFromSubmission(['code'=>'fixture-code', 'state'=>$flow['state'], 'error'=>'']);
        $restart = $manager->continueAuthentication([$callback]);
        $this->response($restart, AuthenticationResponse::RESTART, 'Unlinked identity');
        $this->check($client->calls === 1, 'Only the OAuth callback may fetch the Discord identity');
        $transfer = $restart->createRequest;
        $this->check($transfer !== null, 'Verified identity must be transferable to signup');
        $transfer->maybeLink = [];
        $name = 'DiscordFixture' . bin2hex(random_bytes(5));
        $username = new UsernameAuthenticationRequest();
        $username->username = $name;
        $created = $manager->beginAccountCreation($services->getUserFactory()->newAnonymous(),
            [$username, $transfer], $returnUrl);
        $this->response($created, AuthenticationResponse::PASS, 'Username selection creates account');
        $user = $services->getUserFactory()->newFromName($name);
        $central = CentralAuthUser::getPrimaryInstance($user);
        $this->check($central->exists() && $central->isAttached(), 'New account must be globally attached');
        $this->check($central->getPasswordObject() instanceof InvalidPassword,
            'Discord-only signup must not invent a password');
        $this->check($user->getEmail() === 'discord-fixture@example.invalid' && $user->isEmailConfirmed()
            && $central->getEmail() === $user->getEmail() && $central->getEmailAuthenticationTimestamp() !== null,
            'Discord verified email must populate both local and global account records');
        $links = $services->getService('DiscordAuth.LinkStore');
        $this->check($links->globalId($client->fixtureId) === $central->getId(), 'Global mapping must be saved');
        $this->check($created->loginRequest !== null, 'Signup must support immediate login');
        $loggedIn = $manager->beginAuthentication([$created->loginRequest], $returnUrl);
        $this->response($loggedIn, AuthenticationResponse::PASS, 'Immediate login after signup');
        $this->check($request->getSession()->getUser()->getName() === $name, 'Session must contain the created user');
        $remove = new RemoveDiscordRequest($client->fixtureId);
        $remove->username = $name;
        $remove->action = AuthManager::ACTION_REMOVE;
        $this->check(!$manager->allowsAuthenticationDataChange($remove)->isGood(),
            'Removing the only credential must be rejected');
        $central->setPassword(bin2hex(random_bytes(32)));
        $this->check($manager->allowsAuthenticationDataChange($remove)->isGood(),
            'A password account may unlink Discord');
        $manager->changeAuthenticationData($remove);
        $this->check($links->globalId($client->fixtureId) === null, 'Credential removal must remove the global link');
        $link = $manager->beginAccountLink($user, [$button], $returnUrl);
        $this->response($link, AuthenticationResponse::REDIRECT, 'Existing account starts linking');
        $flow = $this->completeCallback($request, $services, $client, $link->redirectTarget);
        $callback->state = $flow['state'];
        $linked = $manager->continueAccountLink([$callback]);
        $this->response($linked, AuthenticationResponse::PASS, 'Existing account links Discord');
        $this->check($links->globalId($client->fixtureId) === $central->getId(), 'Link must use the global account ID');
        $redirect = $manager->beginAuthentication([$button], $returnUrl);
        $this->response($redirect, AuthenticationResponse::REDIRECT, 'Linked account starts login');
        $flow = $this->completeCallback($request, $services, $client, $redirect->redirectTarget);
        $callback->state = $flow['state'];
        $login = $manager->continueAuthentication([$callback]);
        $this->response($login, AuthenticationResponse::PASS, 'Linked account signs in without username');
        $this->check($login->username === $name, 'Linked login must resolve the correct shared account');
        $duplicate = '888' . random_int(100000000000000, 999999999999999);
        try {
            $links->link($duplicate, $central->getId());
            throw new LogicException('Duplicate link was incorrectly accepted');
        } catch (RuntimeException $exception) {
            $this->check($links->globalId($duplicate) === null, 'Duplicate links must not change ownership');
        }
        $this->output("Verified OAuth signup, verified email, global attachment, immediate and linked login, unlink protection, relinking, and duplicate-link rejection.\n");
        $services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
    }
}
return ExerciseDiscordAuthentication::class;

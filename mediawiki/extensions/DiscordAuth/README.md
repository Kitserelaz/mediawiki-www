# Discord sign-in

This farm-specific extension integrates Discord OAuth2 with MediaWiki 1.46 AuthManager
and CentralAuth SUL3. It uses the core login/signup, LinkAccounts, RemoveCredentials,
account creation checks, and secondary authentication providers (including OATHAuth).
It does not depend on PluggableAuth or WSOAuth: their local account mapping is not used.

## Configuration

The existing runtime-only `farm.json` secret supplies `discord_client_id` and
`discord_client_secret`. Missing credentials hide the authentication button.
No credentials belong in source control, browser configuration, or Docker images.

`docker/mediawiki/runtime.php` derives the single callback from `MW_DOMAIN`:

    https://auth.<MW_DOMAIN>/metawiki/wiki/Special:DiscordCallback

Register this exact URL in the application's Discord Developer Portal → OAuth2 →
Redirects. For the local development farm it is:

    https://auth.localhost/metawiki/wiki/Special:DiscordCallback

Production uses its own configured domain and runtime secret directory. Updating
the local farm.json does not update a remote server's credentials or Discord's
application configuration. Recreate PHP containers after changing a mounted secret
file (an atomic host replacement can leave an existing bind mount on the old inode).
The private secrets directory is mode 0700; files must be readable by the container's
www-data user, as required by the existing `scripts/farm_secrets.py` setup.

Before enabling sign-in on an existing farm, run the normal extension database
updater. It creates `discordauth_link` and `discordauth_transaction` once in
`virtual-centralauth`, not on each wiki. Fresh installations use the same updater hook.

## Authorization lifecycle

Normal Discord sign-in starts a 10-minute authorization transaction in the shared
`discordauth_transaction` table before visiting Discord. It does not start an
AuthManager login or depend on the existing MediaWiki session during that trip.
A separate random, host-only `__Host-` cookie binds each transaction to its browser;
it is Secure, HttpOnly and SameSite=Lax. Separate cookies allow parallel attempts.
Only hashes of the state and browser binding are stored in the database.

The fixed callback checks the binding and expiry, atomically consumes the transaction,
then exchanges the authorization code with Discord. Cancellation and failure also
consume it. A second browser-bound, single-use ticket transfers the verified identity
to the starting wiki on the auth domain, where the normal AuthManager session begins.
This handles the callback and login running in different wiki contexts. The transient
handoff contains the Discord ID, label and email server-side; URLs carry only a random
ticket. Tokens and authorization codes are never stored. Consumed rows are deleted;
abandoned rows expire and are removed when another transaction is issued.

Loss of the pre-callback wiki session no longer invalidates Discord authorization.
Clearing the transaction table or its browser cookie still requires a fresh attempt.
Existing-account linking continues through core AuthManager and requires that account's
existing authenticated session, while using the same transaction/callback validation.

## Account behavior

- The button appears on login and signup and does not submit or validate either
  form. It starts a dedicated Discord-only login. Linked users sign in immediately;
  unlinked users go to a separate username-choice page after Discord authorization.
  The username is never read before Discord identifies the user. Existing users
  can sign in normally and link Discord from Preferences.
- Username selection is prefilled from the Discord username and remains editable.
  Email is imported from Discord instead of requested in the form. Only Discord's
  `verified: true` marks that email as confirmed; missing/unverified email does not
  block identity-based sign-in, and existing accounts' email is not overwritten.
- Every wiki uses the callback above. CentralAuth's signed login return token is
  preserved through username selection, so successful sign-in returns to the wiki
  where the flow started, including its requested page. Successful signup sends
  an HTTP redirect instead of rendering MediaWiki's welcome/return-to page. Direct
  shared-domain entry without a return token redirects to that wiki's requested
  page (or its main page), using the trusted wiki map and core top-level autologin.
- The login and create-account pages place Discord in a separate “Social sign-in” column,
  rendered outside the password form. This replaces the signup statistics panel.
  On narrow screens the sections stack.
- Preferences → User profile → Discord links to the core account-linking and
  credential management pages on the shared authentication domain.
- One Discord ID links to one immutable global account ID, with unique constraints
  in both directions. Username or email matches never authorize linking.
- Renames preserve links. Deleted global accounts cannot authenticate through an
  orphaned link; administrators can remove those records as needed.
- Discord-created accounts have no password initially. They must set a password
  before removing their Discord credential. Existing password accounts keep theirs.
- Local namesakes which are not attached to the mapped global account are rejected.
  CentralAuth locks, rename checks, normal creation restrictions and 2FA still apply.
- `identify email` is requested. No bot, server-membership or role access is needed.
  Access/refresh tokens are not stored. The Discord ID is retained in the link table
  and the returned email is saved to the new account's normal email field.
- OAuth state is random, browser-bound, consumed once and expires after ten minutes.
  The fixed callback resumes only an HTTPS continuation on the same origin. Cancelled
  or failed authorization never creates an account or adds a credential.

## Verification

Run PHP lint and the extension's PHPUnit tests, then the Discord browser tests.
Tests must stub Discord's server-side HTTP responses, not weaken endpoint or TLS
settings. Complete a real Discord consent/login after registering the redirect URI;
automated fixtures cannot verify the application's live portal configuration.

The CLI fixture `tests/maintenance/ExerciseAuthentication.php --wiki=metawiki --exercise`
is restricted to the explicitly selected localhost development farm. It exercises
real AuthManager/CentralAuth operations using a fake Discord identity and creates
uniquely named development fixture accounts. It cannot run via the web.

`DISCORD_BROWSER_FIXTURES=1` enables the optional browser fixture cases in
`docker/tests/discord.spec.js`. They run the guarded CLI fixture against a temporary
authorization transaction after deleting the original wiki session cookies, then exercise the real username form and the full CentralAuth
return to both meta and test wikis. The default cases only intercept navigation
to Discord and check callback validation and cancellation.

Development disables `PasswordAttemptThrottle` to allow repeated login/OAuth tests.
Production retains the normal configured login limits.

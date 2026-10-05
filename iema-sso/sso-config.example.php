<?php
/**
 * IEMA Portal connector — settings for THIS app.
 * Copy the values from Portal → People & access → App connections.
 */

// Which app this is: auditops | crmops | hrops | finops
define('IEMA_SSO_APP_KEY', 'crmops');

// The Portal's address (no trailing slash).
define('IEMA_SSO_PORTAL_URL', 'https://portal.iemacert.com');

// This app's private connection key from the Portal (64 characters).
// While this still says PASTE…, the connector is switched OFF and the
// app keeps using its own login exactly as before.
define('IEMA_SSO_SECRET', 'PASTE_SECRET_FROM_PORTAL');

// false = Portal sign-in is offered, but the app's own login still works.
// true  = everyone must come through the Portal (the app's own login is
//         kept only as an emergency door for IT, at login.php?local=1).
define('IEMA_SSO_ENFORCE', false);

// Create an account in this app automatically the first time someone
// with Portal access arrives (using the role the Portal gives them).
define('IEMA_SSO_AUTO_CREATE', true);

// How often (seconds) to re-confirm with the Portal that the person is
// still active — suspensions take effect within this time.
define('IEMA_SSO_RECHECK_SECONDS', 300);

=== Just Wanna Be a User ===
Tags: user switching, login as user, impersonate, users, admin
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Log in as any user from the Users screen, see the site exactly as they do, then jump straight back to your own admin account.

== Description ==

Just Wanna Be a User adds a **Log in as this user** link to every row of the Users screen. One click logs you in as that user and takes you to the site's homepage (or any page you choose), so you can check what they see: their account pages, their courses, their orders, their permissions.

While you are logged in as someone else, a small bar is pinned to the top center of every page showing who you are viewing as, with a **Go back to admin** button. That button logs you back in to your own account and returns you to the Users screen, or any admin page you choose. Drag the bar out of the way, or shrink it to two small buttons. You never need the other user's password, and you never need to type yours again.

= Features =

* "Log in as this user" row action on the Users screen.
* "Go back to admin" bar at the top center of the site while you are switched.
* The bar also appears on pages that block the user, such as maintenance mode screens and "Sorry, you are not allowed to access this page" errors, so you can't get stuck.
* Drag the bar anywhere, or minimize it to two small buttons: go back to admin and expand. It remembers where you left it.
* Choose where you land in **Tools > Just Wanna Be a User**: the homepage, dashboard, profile or any page when switching in, and the Users screen, dashboard, homepage or any admin page when going back.
* Works out of the box. The defaults are the homepage on the way in and the Users screen on the way back.

= Who can use it =

Only users who are allowed to edit the target user can switch to them. On a single site that means administrators. On multisite it means super admins. You can't switch to yourself, and the link isn't shown where you don't have permission.

= Security =

* Both the switch and the switch-back links are protected by WordPress nonces.
* Before switching, the plugin keeps your own session alive and stores a signed reference to it in an HttpOnly cookie. "Go back to admin" only works when that signature is valid for your still-active session, so it can't be used to take over any other account.
* When you go back, the temporary session that was created for the other user is destroyed.

Found a security issue? Please report it privately through GitHub's private vulnerability reporting at https://github.com/vPereira04/just-wanna-be-a-user/security/advisories/new, rather than in the public support forum. Include the plugin version, the steps to reproduce, and what an attacker could do. You'll get a first response within 5 business days, and we'll keep you posted until a fix ships. See SECURITY.md in the plugin folder for the full policy.

= Privacy =

This plugin does not collect, store or send any data to external services. It sets one cookie, `jwbau_origin_{hash}`, only while an administrator is logged in as another user. The cookie holds a signed reference to the administrator's own session and is deleted when they go back or log out. It's a strictly necessary authentication cookie: it's set only when an administrator clicks **Log in as this user**, it's needed to log them back in, and it's never used for tracking, so it doesn't need cookie consent. The bar's position and minimized state are kept in the browser's local storage, under `jwbau_bar`, and never leave the browser.

== Installation ==

1. Upload the `just-wanna-be-a-user` folder to `/wp-content/plugins/`, or install the plugin from the **Plugins > Add New** screen.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Users**, hover over a user and click **Log in as this user**.
4. Optional: choose where you land in **Tools > Just Wanna Be a User**, or click **Settings** under the plugin on the Plugins screen.

== Frequently Asked Questions ==

= Do I need the user's password? =

No. You are logged in as the user directly, and their password is never touched.

= How do I get back to my own account? =

Click **Go back to admin** in the bar at the top of the page. If the bar is minimized, click its arrow button. You are logged back in as yourself and taken to the Users screen, or to the page set in **Tools > Just Wanna Be a User**.

= Can I land somewhere other than the homepage and the Users screen? =

Yes. Go to **Tools > Just Wanna Be a User**. For each side, pick a preset or type a custom path. Custom paths must stay on your own site.

= The bar is covering something on the page. =

Drag it by its dotted grip to anywhere on the screen, or click the minus button to shrink it to two small buttons. Double-click the grip to put it back at the top center.

= My site is in maintenance mode and the user is blocked. Am I stuck? =

No. The "Go back to admin" bar is added even to pages that stop early, like maintenance mode screens and wp-admin permission errors.

= What if I just close the browser? =

Switching uses a session cookie, so closing the browser ends it. Log in again as usual.

= The "Go back to admin" link says it has expired. =

Your own admin session ended while you were switched (for example, it timed out or you logged out everywhere). Log in to your admin account again.

= Can I switch to another administrator? =

Yes, as long as you are allowed to edit that user.

= What is removed when I delete the plugin? =

Its one setting, `jwbau_settings`. The plugin creates no database tables.

== Screenshots ==

1. The "Log in as this user" link in a user's row actions on the Users screen.
2. The "Go back to admin" bar pinned to the top center of the site while you are logged in as another user.

== Changelog ==

Releases that fix a security issue say so in their entry, prefixed with **Security:**.

= 1.0.0 =
* Initial release.

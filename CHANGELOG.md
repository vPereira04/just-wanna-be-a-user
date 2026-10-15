# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Releases that fix a security issue say so in their entry, prefixed with **Security:**.

## [1.0.0] - Unreleased

### Added

- "Log in as this user" row action on the Users screen. It's shown only for users you're allowed to edit, and never on your own row.
- "Go back to admin" bar pinned to the top center of the site and wp-admin while you're switched. It shows who you're viewing as.
- The bar also appears on pages that exit early, such as maintenance-mode screens and `wp_die()` permission errors, so admins can't get stuck.
- The bar can be dragged anywhere and minimized to two small buttons (go back to admin and expand). The browser remembers its position and state, and double-clicking the grip puts it back.
- **Tools > Just Wanna Be a User** settings page, linked as **Settings** on the Plugins screen. It sets where you land after switching in (homepage by default) and after going back (Users screen by default), using presets or a custom path on your site.
- Uninstalling removes the plugin's one option, `jwbau_settings`.
- Switching back restores the admin's original session without a password and destroys the temporary session created for the user.

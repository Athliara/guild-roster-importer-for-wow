=== Guild Roster Importer for WoW ===
Contributors: athlios
Tags: guild, roster, battle.net, blizzard, gaming
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.5
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Display a World of Warcraft guild roster and detailed character profiles on WordPress using the Blizzard Battle.net API.

== Description ==
Guild Roster Importer for WoW connects to the Blizzard Battle.net API and renders your World of Warcraft guild data directly on your WordPress site.

It is built for guild websites that want a cleaner, Blizzard-inspired presentation without manually maintaining member lists or character pages.

Main features:

* Admin settings for guild name, realm, region, Battle.net credentials, appearance, and saved displays.
* Shortcode output with selectable roster themes and saved display presets.
* Character profile pages with equipment, stats, specializations, Mythic Plus, raid progress, and professions.
* Daily automatic roster refresh through WP-Cron plus manual sync actions from plugin settings.
* Multiple display configurations for raid teams, rank groups, or hand-picked character lists.

Typical uses:

* Display your main guild roster on a page with one shortcode.
* Create multiple saved displays for raid teams, rank groups, or hand-picked character lists.
* Let visitors open character profile pages with equipment, specialization, dungeon, raid, and profession data pulled from Blizzard.

Developer and owner: Athlios
This plugin is maintained for Gordian Knot and shared for public use.

Plugin website: https://a-wd.eu/

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/` or install via the WordPress plugin uploader.
2. Activate `Guild Roster Importer for WoW` in the Plugins screen.
3. Open `Guild Roster` from the left admin menu and enter your guild/API details.
4. Add `[guilroim_roster]` to a page, post, or widget.

== Frequently Asked Questions ==
= Does this plugin require Battle.net credentials? =
Yes. You need a Battle.net API Client Key and Client Secret from Blizzard's developer portal.

= How do I display the roster on my site? =
Add the `[guilroim_roster]` shortcode to a page, post, or widget after saving your guild, realm, region, and API credentials.

= Can I create more than one roster layout? =
Yes. The plugin includes saved displays so you can create multiple shortcode-ready roster variants with different titles, rank filters, sorting, paging, or selected characters.

= What data is shown on character profiles? =
Character profiles can include equipment, stats, specializations, Mythic Plus data, raid progress, and professions when Blizzard exposes that data for the selected character.

= How often is the roster updated automatically? =
The plugin schedules a daily refresh every 24 hours via WP-Cron.

== External Services ==

This plugin connects to an external service to load World of Warcraft data. Static presentation assets are bundled locally with the plugin.

Battle.net API is used to request an OAuth access token and retrieve guild, roster, character, Mythic Plus, raid, profession, and other World of Warcraft profile data. The plugin sends the configured region, guild name, realm, character names, Battle.net client ID, and Battle.net client secret when the site owner saves plugin settings, refreshes the guild roster, opens a character profile page, or when a scheduled sync runs.

Some Battle.net API responses include Blizzard-hosted media URLs for character portraits, character renders, and item icons. When those images are needed, the plugin fetches them server-side and stores cached local copies in the WordPress uploads directory, so visitor pages load the local cached files instead of third-party image URLs.

Battle.net API terms: https://www.blizzard.com/legal/a2989b50-5f16-43b1-abec-2ae17cc09dd6/blizzard-developer-api-terms-of-use
Battle.net API privacy policy: https://www.blizzard.com/en-us/legal/a4380ee5-5c8d-4e3b-83b7-ea26d01a9918/

The plugin does not load third-party tooltip scripts, remote CSS, remote JavaScript, or non-cached third-party presentation images on visitor pages.

== Screenshots ==
1. Guild Roster Importer for WoW settings screen.
2. Front-end guild roster display.
3. Character profile page with equipment and progression panels.

== Changelog ==
= 1.0.5 =
- Updated plugin metadata for WordPress 7.0 compatibility.

= 1.0.4 =
- Localized presentation assets and removed third-party tooltip/media loading from the plugin output.
- Updated WordPress.org prefixes for stored data, actions, script handles, and the public shortcode.
- Added an admin Tooltips tab with guidance for site-specific tooltip integrations.

= 1.0.3 =
- Renamed plugin constants to use the longer WordPress.org review prefix.
- Moved the character profile JavaScript into a dedicated asset file.

= 1.0.2 =
- Replaced the character profile inline heredoc stylesheet with a dedicated CSS asset file.
- Clarified external service documentation and updated submission metadata.

= 1.0.1 =
- Updated roster theme accessibility and styling for the non-default variants.
- Refined Mythic+ roster score handling and character profile dungeon background mapping.
- Shortened the admin sidebar menu label to `Guild Roster`.

= 1.0.0 =
- Initial public release.
- Displays World of Warcraft guild rosters and detailed character profiles using the Blizzard Battle.net API.
- Includes plugin settings, shortcode-based roster displays, saved display presets, and Blizzard-inspired character profile pages.

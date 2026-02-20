=== Bento + PMPro Integration ===
Contributors: arcalms
Tags: bento, pmpro, paid memberships pro, sensei, lms, email, automation
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects Paid Memberships Pro and Sensei LMS to Bento, sending events when members check out, change plans, cancel, and more.

== Description ==

A standalone bridge plugin that fires [Bento](https://bentonow.com/) events when Paid Memberships Pro membership actions occur and when students interact with Sensei LMS courses — without modifying the Bento WordPress SDK.

Events are queued through Action Scheduler so Bento API latency or downtime never blocks checkout or other user-facing requests.

= PMPro Events =

* Member completes checkout (`$PmproMemberCheckout`)
* Membership level changes (`$PmproLevelChanged`)
* Membership cancelled (`$PmproCancelled`)
* Recurring payment succeeded (`$PmproPaymentCompleted`)
* Recurring payment failed (`$PmproPaymentFailed`)
* Membership expired (`$PmproMembershipExpired`)

= Sensei LMS Events =

* Student enrolled in a course (`$SenseiCourseEnrolled`)
* Student unenrolled from a course (`$SenseiCourseUnenrolled`)
* Student started a course (`$SenseiCourseStarted`)
* Student completed a course (`$SenseiCourseCompleted`)
* Student completed a lesson (`$SenseiLessonCompleted`)
* Student submitted a quiz (`$SenseiQuizSubmitted`)

= Custom Field Mappings =

Each event can be configured with custom field mappings that set Bento subscriber fields when the event fires. Mappings support three value sources: a static string, a WordPress user meta key, or a value from the event itself (e.g. `level_name`, `course_title`). An optional condition lets you apply a mapping only when a specific event value matches — for example, only for members on the "Monthly" plan.

= Bulk Sync =

A built-in bulk-sync tool lets you send the checkout or course-enrolled event for all existing members/students, applying your current field mappings. Sync runs in the background via Action Scheduler and shows live progress.

= Requirements =

* [Bento WordPress SDK](https://github.com/bentonow/bento-wordpress-sdk) — must be installed and configured with your site key and API keys.
* [Paid Memberships Pro](https://www.paidmembershipspro.com/) — for membership events (optional if you only need Sensei events).
* [Sensei LMS](https://senseilms.com/) — for course/lesson/quiz events (optional if you only need PMPro events).

== Installation ==

1. Make sure the **Bento WordPress SDK** plugin is installed and active first.
2. Upload and activate this plugin.
3. Go to **Settings → Bento Membership Integration**.
4. Enable the events you want to track and optionally configure custom field mappings.
5. Use the **Send test event** button to confirm the connection is working.

== Frequently Asked Questions ==

= Does this modify the Bento WordPress SDK? =

No. It works alongside the SDK as a standalone plugin and calls the SDK's public API.

= Does it block checkout if Bento's API is slow? =

No. Events are queued through Action Scheduler and fired in the background. The Bento API call happens after the checkout request has completed.

= What happens if Bento's API is down? =

Action Scheduler will mark the action as failed, which you can see in WP Admin → Tools → Scheduled Actions. You can retry failed actions from there.

= Can I use this without PMPro? =

Yes — if PMPro is not active, only Sensei LMS hooks are registered. The settings page still works for configuring Sensei events.

= Can I use this without Sensei LMS? =

Yes — if Sensei is not active, only PMPro hooks are registered.

== Screenshots ==

1. Settings page — enable events and configure custom field mappings per event.
2. Bulk sync — send existing members and course enrollments to Bento in the background.

== Changelog ==

= 1.2.0 =
* Guard against fatal errors if PMPro or Sensei is deactivated while the plugin remains active.
* Add activation-time check — plugin will not activate unless the Bento WordPress SDK is present.
* Add deactivation hook to cancel pending Action Scheduler actions.
* Guard all Bento SDK calls with method_exists() in addition to class_exists() for forward compatibility.
* Add Send test event button to the settings page.
* Show admin notice when Bento SDK is missing or API credentials are not configured.
* Add uninstall.php to clean up options, transients, and scheduled actions on deletion.
* Report API errors during bulk sync in the status message rather than silently discarding them.
* Remove duplicate resolve_custom_fields / get_event_config methods (DRY).
* Fix unsafe HTML string concatenation in admin JS.

= 1.1.0 =
* Queue real-time PMPro and Sensei events through Action Scheduler so Bento API latency no longer blocks checkout.
* Fix duplicate event when checkout fires level-changed in the same request.

= 1.0.0 =
* Initial release.
* PMPro events: checkout, level changed, cancelled, payment completed, payment failed, expired.
* Sensei LMS events: course enrolled, unenrolled, started, completed; lesson completed; quiz submitted.
* Custom field mappings with static, user meta, and event data sources, plus conditional logic.
* Bulk sync for existing PMPro members and Sensei course enrollments.

== Upgrade Notice ==

= 1.2.0 =
Recommended update. Fixes potential fatal errors when PMPro or Sensei is temporarily deactivated, and adds a safety check at activation time.

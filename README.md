# Bento + PMPro Integration

A WordPress plugin that connects [Paid Memberships Pro](https://www.paidmembershipspro.com/) and [Sensei LMS](https://senseilms.com/) to [Bento](https://bentonow.com/), firing events when members check out, change plans, cancel, and more — including when students enrol in or complete courses.

Built as a standalone bridge plugin so it works alongside the official Bento WordPress SDK without modifying it.

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- [Bento WordPress SDK](https://github.com/bentonow/bento-wordpress-sdk) (configured with your site key, publishable key, and secret key)
- [Paid Memberships Pro](https://www.paidmembershipspro.com/) — for membership events
- [Sensei LMS](https://senseilms.com/) — for course/lesson/quiz events (optional)

---

## Installation

1. Download the ZIP from the [Releases](../../releases) page (or the green **Code → Download ZIP** button).
2. In WordPress admin → **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP, install, and activate.
4. Make sure the Bento WordPress SDK is also installed and configured with your API credentials.

---

## Events

### Paid Memberships Pro

| Event | Default Bento Name |
|---|---|
| Member completes checkout | `$PmproMemberCheckout` |
| Membership level changes | `$PmproLevelChanged` |
| Membership cancelled | `$PmproCancelled` |
| Recurring payment succeeded | `$PmproPaymentCompleted` |
| Recurring payment failed | `$PmproPaymentFailed` |
| Membership expired | `$PmproMembershipExpired` |

### Sensei LMS

| Event | Default Bento Name |
|---|---|
| Student enrolled in a course | `$SenseiCourseEnrolled` |
| Student unenrolled from a course | `$SenseiCourseUnenrolled` |
| Student started a course | `$SenseiCourseStarted` |
| Student completed a course | `$SenseiCourseCompleted` |
| Student completed a lesson | `$SenseiLessonCompleted` |
| Student submitted a quiz | `$SenseiQuizSubmitted` |

---

## Configuration

Go to **Settings → Bento Membership Integration**.

Each event has:

- **Enable** — toggle the event on or off.
- **Event Name** — override the default Bento event name if needed.
- **Custom Field Mappings** — set subscriber fields in Bento when the event fires.

### Custom Field Mappings

Each row in the mapping table has:

| Column | Description |
|---|---|
| **Bento Field** | The subscriber field to set in Bento. Autocompletes from your existing Bento fields. |
| **Set To** | Where the value comes from: **Static** (a fixed string), **User Meta** (a WordPress user meta key), or **Event Data** (a value from the event itself, e.g. `level_name`). |
| **Value** | The static string, meta key, or event data key to use. |
| **Only if…** | *(Optional)* An event data key to check as a condition (e.g. `level_name`). |
| **…equals** | *(Optional)* The value to match. Autocompletes from real data — PMPro level names, Sensei course titles, etc. |

### Example: per-plan field mapping on checkout

To set a different Bento field depending on which plan a member buys:

| Bento Field | Set To | Value | Only if… | …equals |
|---|---|---|---|---|
| `plan_free_status` | Static | `purchased` | `level_name` | `Free` |
| `plan_monthly_status` | Static | `purchased` | `level_name` | `Monthly` |

### Example: mark a subscriber as churned on cancellation

| Bento Field | Set To | Value | Only if… | …equals |
|---|---|---|---|---|
| `plan_free_status` | Static | `churned` | `last_level_names` | `Free` |
| `plan_monthly_status` | Static | `churned` | `last_level_names` | `Monthly` |

---

## Available Event Data Keys

These are the values available for **Set To: Event Data** and **Only if…** conditions for each event.

| Event | Available keys |
|---|---|
| PMPro Checkout | `level_id`, `level_name`, `order_total`, `payment_type` |
| PMPro Level Changed | `level_id`, `new_level_name`, `old_level_names` |
| PMPro Cancelled | `last_level_names` |
| PMPro Payment Completed | `order_total`, `level_name` |
| PMPro Payment Failed | `level_name` |
| PMPro Expired | `level_id`, `level_name` |
| Sensei Course Enrolled / Unenrolled / Started / Completed | `course_id`, `course_title` |
| Sensei Lesson Completed | `lesson_id`, `lesson_title`, `course_id` |
| Sensei Quiz Submitted | `quiz_id`, `grade`, `pass`, `quiz_pass_percentage`, `quiz_grade_type` |

---

## License

MIT

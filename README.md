# HC DORB Registration

WordPress plugin for **DORB healthcare registration** with an optional patient-samples opt-in.

**Plugin file:** `hc-dorb-registration-plugin.php`  
**Version:** `1.0.0`  
**Text domain:** `hc-dorb-registration`  
**Database table:** `{prefix}hc_dorb_registration_submissions` (e.g. `wp_hc_dorb_registration_submissions`)

---

## Installation

1. Copy `hc-dorb-registration-plugin` into `wp-content/plugins/`.
2. Activate **HC DORB Registration** under **Plugins**.
3. Add `[hc_dorb_registration]` to a page or post.

---

## Shortcode

```text
[hc_dorb_registration]
```

### Attributes

| Attribute | Default | Purpose |
|-----------|---------|---------|
| `thank_you_url` | Current page | Custom URL after successful submit |
| `contact_email` | `info@babybrands.com` | Shown on thank-you screen |
| `notification_email` | *(empty)* | If set, submission summary is emailed here |

### Example

```text
[hc_dorb_registration contact_email="support@example.com" notification_email="admin@example.com"]
```

---

## Form differences vs HC Registration

This DORB form is based on the Pampers HC registration plugin with these changes:

- **Added** (below “expecting parents per week”): required **Yes / No** — *Would you like to receive sample of Diapers, Sudocrem, wipes for your patients*
- **Removed:** Number of packages (12 / 24)
- **Removed:** Pampers distribution confirmation checkboxes

---

## Admin

- **HC DORB Registrations** menu — list, search, export CSV, view, edit, delete submissions

---

## Database columns

`healthcare_id`, `organization_name`, contact fields, address fields, `category`, `category_other`, `patients_type`, `weekly_expecting_parents`, **`receive_patient_samples`** (`Yes` / `No`), `comments`, `created_at`

Table is created via `dbDelta()` on `init`.

---

## Files

| File | Purpose |
|------|---------|
| `hc-dorb-registration-plugin.php` | Bootstrap, form, admin, DB |
| `includes/class-hcdr-submissions-list-table.php` | Admin list table |
| `assets/css/hc-dorb-registration.css` | Form styles |

---

## Hooks

- **`hcdr_contact_email`** — Filter contact email on thank-you / validation
- **`hcdr_send_notification_email`** — `(bool $send, int $insert_id)` — Return `false` to skip notification email
- **`hcdr_created_at_timezone`** — Filter timezone for `created_at` (default `America/New_York`)

Form posts to `admin-post.php` with `action=hcdr_dorb_register`.

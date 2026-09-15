<?php
/**
 * Plugin Name: HC DORB Registration
 * Description: DORB healthcare registration form with sample opt-in. Shortcode [hc_dorb_registration].
 * Version: 1.0.0
 * Author: BabyBrands
 * Text Domain: hc-dorb-registration
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HCDR_VERSION', '1.0.0');
define('HCDR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HCDR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('HCDR_ADMIN_PAGE_SLUG', 'hcdr-submissions');
/** Posted `action` / nonce scheme for public form (`admin-post.php`). */
define('HCDR_POST_ACTION', 'hcdr_dorb_register');
/** Default timezone used when saving `created_at`. */
define('HCDR_CREATED_AT_TIMEZONE', 'America/New_York');

require_once HCDR_PLUGIN_PATH . 'includes/class-hcdr-submissions-list-table.php';

/**
 * @return string
 */
function hcdr_get_submissions_table_name()
{
    global $wpdb;

    return $wpdb->prefix . 'hc_dorb_registration_submissions';
}

/**
 * Build a WHERE fragment and prepare args for submission list / export search.
 *
 * @return array{sql:string,args:array<int,string>}
 */
function hcdr_submissions_search_where_clause($wpdb, $search)
{
    $search = trim((string) $search);
    if ($search === '') {
        return ['sql' => '', 'args' => []];
    }

    $like = '%' . $wpdb->esc_like($search) . '%';
    $fields = [
        'healthcare_id',
        'organization_name',
        'contact_first_name',
        'contact_last_name',
        'email',
        'city',
        'province',
        'phone',
        'postal_code',
        'category',
        'category_other',
        'job_title',
        'department',
        'address_1',
        'comments',
    ];
    $parts = [];
    $args = [];
    foreach ($fields as $field) {
        $parts[] = '`' . $field . '` LIKE %s';
        $args[] = $like;
    }

    return [
        'sql' => '(' . implode(' OR ', $parts) . ')',
        'args' => $args,
    ];
}

/**
 * CSV column order for exports (matches submissions table).
 *
 * @return string[]
 */
function hcdr_submissions_csv_columns()
{
    return [
        'no',
        'healthcare_id',
        'organization_name',
        'contact_first_name',
        'contact_last_name',
        'email',
        'job_title',
        'department',
        'phone',
        'extension',
        'address_1',
        'suite',
        'city',
        'province',
        'postal_code',
        'category',
        'category_other',
        'patients_type',
        'weekly_expecting_parents',
        'receive_patient_samples',
        'comments',
        'created_at',
    ];
}

/**
 * Attempt to fix common UTF-8/Windows-1252 mojibake (e.g. "Ã‰" -> "É", "â€™" -> "’").
 *
 * If conversion doesn't produce valid UTF-8, the original string is returned.
 *
 * @param string $value
 * @return string
 */
function hcdr_csv_normalize_value($value)
{
    $value = (string) $value;
    if ($value === '') {
        return $value;
    }

    // Normalize common punctuation to ASCII to avoid viewer-specific encoding issues.
    $value = str_replace(
        ["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2026}"],
        ["'", "'", '"', '"', '-', '-', '...'],
        $value
    );

    // Convert accented characters to closest ASCII equivalent for maximum compatibility.
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            return $ascii;
        }
    }

    return $value;
}

/**
 * Timezone used for `created_at` persistence and formatting.
 *
 * Filter: `hcdr_created_at_timezone` (string $tzId).
 *
 * @return DateTimeZone
 */
function hcdr_created_at_timezone()
{
    $tzId = (string) apply_filters('hcdr_created_at_timezone', HCDR_CREATED_AT_TIMEZONE);
    if ($tzId === '') {
        $tzId = HCDR_CREATED_AT_TIMEZONE;
    }

    try {
        return new DateTimeZone($tzId);
    } catch (Exception $e) {
        return new DateTimeZone('UTC');
    }
}

/**
 * @return string MySQL datetime in ET (or filtered timezone).
 */
function hcdr_created_at_mysql()
{
    $tz = hcdr_created_at_timezone();
    try {
        $dt = new DateTimeImmutable('now', $tz);

        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return gmdate('Y-m-d H:i:s');
    }
}

/**
 * Convert a stored MySQL datetime (assumed ET / filtered tz) to timestamp.
 *
 * @param string $mysqlDatetime "Y-m-d H:i:s"
 * @return int|null
 */
function hcdr_created_at_to_timestamp($mysqlDatetime)
{
    $mysqlDatetime = trim((string) $mysqlDatetime);
    if ($mysqlDatetime === '') {
        return null;
    }

    $tz = hcdr_created_at_timezone();
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDatetime, $tz);
    if ($dt instanceof DateTimeImmutable) {
        return $dt->getTimestamp();
    }

    $ts = strtotime($mysqlDatetime);

    return $ts ? (int) $ts : null;
}

function hcdr_maybe_install_table()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = hcdr_get_submissions_table_name();
    $charsetCollate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        healthcare_id VARCHAR(64) NULL DEFAULT NULL,
        organization_name VARCHAR(255) NULL DEFAULT NULL,
        contact_first_name VARCHAR(250) NULL DEFAULT NULL,
        contact_last_name VARCHAR(120) NULL DEFAULT NULL,
        email VARCHAR(250) NULL DEFAULT NULL,
        job_title VARCHAR(250) NULL DEFAULT NULL,
        department VARCHAR(250) NULL DEFAULT NULL,
        phone VARCHAR(40) NULL DEFAULT NULL,
        extension VARCHAR(250) NULL DEFAULT NULL,
        address_1 VARCHAR(255) NULL DEFAULT NULL,
        suite VARCHAR(120) NULL DEFAULT NULL,
        city VARCHAR(120) NULL DEFAULT NULL,
        province VARCHAR(10) NULL DEFAULT NULL,
        postal_code VARCHAR(25) NULL DEFAULT NULL,
        category VARCHAR(120) NULL DEFAULT NULL,
        category_other VARCHAR(255) NULL DEFAULT NULL,
        patients_type VARCHAR(40) NULL DEFAULT NULL,
        weekly_expecting_parents VARCHAR(120) NULL DEFAULT NULL,
        receive_patient_samples VARCHAR(3) NULL DEFAULT NULL,
        comments TEXT NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_email (email),
        KEY idx_created_at (created_at)
    ) {$charsetCollate};";

    dbDelta($sql);
}

add_action('init', static function () {
    hcdr_maybe_install_table();
});

function hcdr_get_canadian_provinces()
{
    return [
        'AB' => 'Alberta',
        'BC' => 'British Columbia',
        'MB' => 'Manitoba',
        'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia',
        'NT' => 'Northwest Territories',
        'NU' => 'Nunavut',
        'ON' => 'Ontario',
        'PE' => 'Prince Edward Island',
        'QC' => 'Quebec',
        'SK' => 'Saskatchewan',
        'YT' => 'Yukon',
    ];
}

/**
 * @return string[]
 */
function hcdr_get_registration_categories()
{
    return [
        'Doula / Midwife',
        'Ultrasound',
        'Prenatal Instructor',
        'Hospital',
        'Dr. / OBGYN',
        'Pregnancy Centre',
        'Medical Centre',
        'Other',
    ];
}

/**
 * @return string[]
 */
function hcdr_get_patients_types()
{
    return ['Prenatal', 'Postnatal', 'Both'];
}

/**
 * Validate submission fields (DB column keys). Used by public form and admin save.
 *
 * @param array<string,mixed> $input Raw values (e.g. from POST after wp_unslash).
 * @param bool $require_confirm Unused (kept for admin compatibility).
 * @return array<string,mixed>|WP_Error Row for insert/update (nulls for empty optional strings).
 */
function hcdr_validate_submission_row(array $input, $require_confirm = true)
{
    $healthcareId = sanitize_text_field((string) ($input['healthcare_id'] ?? ''));
    $healthcareId = strtoupper(trim($healthcareId));
    if ($healthcareId === 'HCP') {
        // Treat bare prefix as "not set" unless they add digits/characters.
        $healthcareId = '';
    }

    $organization = sanitize_text_field($input['organization_name'] ?? '');
    $first = sanitize_text_field($input['contact_first_name'] ?? '');
    $last = sanitize_text_field($input['contact_last_name'] ?? '');
    $email = sanitize_email($input['email'] ?? '');
    $job = sanitize_text_field($input['job_title'] ?? '');
    $department = sanitize_text_field($input['department'] ?? '');
    $phone = sanitize_text_field($input['phone'] ?? '');
    $extension = sanitize_text_field($input['extension'] ?? '');
    $address1 = sanitize_text_field($input['address_1'] ?? '');
    $suite = sanitize_text_field($input['suite'] ?? '');
    $city = sanitize_text_field($input['city'] ?? '');
    $province = sanitize_text_field($input['province'] ?? '');
    $postalRaw = strtoupper(preg_replace('/\s+/', ' ', trim((string) ($input['postal_code'] ?? ''))));
    $postal = $postalRaw;
    $category = sanitize_text_field($input['category'] ?? '');
    $categoryOther = sanitize_text_field((string) ($input['category_other'] ?? ''));
    $patientsType = sanitize_text_field($input['patients_type'] ?? '');
    $weeklyExpecting = preg_replace('/\D/', '', (string) ($input['weekly_expecting_parents'] ?? ''));
    $receivePatientSamples = sanitize_text_field($input['receive_patient_samples'] ?? '');
    $comments = sanitize_textarea_field((string) ($input['comments'] ?? ''));

    $allowedProvinces = array_keys(hcdr_get_canadian_provinces());
    if ($province !== '' && !in_array($province, $allowedProvinces, true)) {
        $province = '';
    }

    if ($category !== '' && !in_array($category, hcdr_get_registration_categories(), true)) {
        $category = '';
    }

    if ($category !== 'Other') {
        $categoryOther = '';
    }

    if ($patientsType !== '' && !in_array($patientsType, hcdr_get_patients_types(), true)) {
        $patientsType = '';
    }

    if (
        $organization === '' || $first === '' || $last === '' || !is_email($email) ||
        $job === '' || $department === '' || $phone === '' || $address1 === '' ||
        $city === '' || $province === '' || $postal === '' || $category === '' || $patientsType === '' ||
        $weeklyExpecting === '' || !in_array($receivePatientSamples, ['Yes', 'No'], true)
    ) {
        return new WP_Error('hcdr_required', __('Please complete all required fields.', 'hc-dorb-registration'));
    }

    if ($extension !== '' && !ctype_digit($extension)) {
        return new WP_Error('hcdr_extension', __('Extension must contain digits only.', 'hc-dorb-registration'));
    }

    if (!preg_match('/^[A-Z]\d[A-Z] \d[A-Z]\d$/', $postal)) {
        return new WP_Error('hcdr_postal', __('Please enter a valid postal code (format A1A 1A1).', 'hc-dorb-registration'));
    }

    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) !== 10) {
        return new WP_Error('hcdr_phone', __('Please enter a valid 10-digit phone number.', 'hc-dorb-registration'));
    }

    if ($category === 'Other' && trim($categoryOther) === '') {
        return new WP_Error('hcdr_required', __('Please specify your category.', 'hc-dorb-registration'));
    }

    return [
        'healthcare_id' => $healthcareId === '' ? null : $healthcareId,
        'organization_name' => $organization,
        'contact_first_name' => $first,
        'contact_last_name' => $last,
        'email' => $email,
        'job_title' => $job,
        'department' => $department,
        'phone' => $phone,
        'extension' => $extension === '' ? null : $extension,
        'address_1' => $address1,
        'suite' => $suite === '' ? null : $suite,
        'city' => $city,
        'province' => $province,
        'postal_code' => $postal,
        'category' => $category,
        'category_other' => $categoryOther === '' ? null : $categoryOther,
        'patients_type' => $patientsType,
        'weekly_expecting_parents' => $weeklyExpecting,
        'receive_patient_samples' => $receivePatientSamples,
        'comments' => $comments === '' ? null : $comments,
    ];
}

function hcdr_get_redirect_url()
{
    $redirectUrl = '';

    if (is_singular()) {
        $postId = get_queried_object_id();
        if ($postId) {
            $redirectUrl = get_permalink($postId);
        }
    }

    if ($redirectUrl === '') {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $redirectUrl = home_url($requestUri);
    }

    return remove_query_arg(['hcdr_status', 'hcdr_message'], $redirectUrl);
}

function hcdr_get_feedback()
{
    return [
        'status' => isset($_GET['hcdr_status']) ? sanitize_key(wp_unslash($_GET['hcdr_status'])) : '',
        'message' => isset($_GET['hcdr_message']) ? sanitize_text_field(wp_unslash((string) $_GET['hcdr_message'])) : '',
    ];
}

function hcdr_build_feedback_url($status, $message = '', $redirectUrl = '')
{
    if ($redirectUrl === '') {
        $redirectUrl = hcdr_get_redirect_url();
    }

    $args = ['hcdr_status' => $status];
    if ($message !== '') {
        $args['hcdr_message'] = $message;
    }

    return add_query_arg($args, $redirectUrl);
}

/**
 * Shortcode: [hc_dorb_registration]
 *
 * Attributes:
 * - thank_you_url — optional custom URL after success
 * - contact_email — shown on thank-you screen
 * - notification_email — optional; if set, submission summary is emailed there. Empty = no email.
 */
function hcdr_render_form_shortcode($atts, $content = '', $tag = '')
{
    $shortcodeTag = $tag !== '' ? $tag : 'hc_dorb_registration';
    $atts = shortcode_atts(
        [
            'thank_you_url' => '',
            'contact_email' => 'info@babybrands.com',
            'notification_email' => '',
        ],
        $atts,
        $shortcodeTag
    );

    $thankYouUrl = esc_url(trim((string) $atts['thank_you_url']));
    $contactEmail = sanitize_email((string) $atts['contact_email']);
    if ($contactEmail === '' || !is_email($contactEmail)) {
        $contactEmail = 'info@babybrands.com';
    }
    $contactEmail = (string) apply_filters('hcdr_contact_email', $contactEmail, $shortcodeTag);

    $notifyEmail = sanitize_email((string) $atts['notification_email']);

    $feedback = hcdr_get_feedback();
    $redirectUrl = hcdr_get_redirect_url();
    $successRedirectUrl = $thankYouUrl !== '' ? $thankYouUrl : $redirectUrl;
    $successRedirectUrl = wp_validate_redirect($successRedirectUrl, $redirectUrl);
    $successRedirectUrl = remove_query_arg(['hcdr_status', 'hcdr_message'], $successRedirectUrl);
    $formPageUrl = remove_query_arg(['hcdr_status', 'hcdr_message'], $redirectUrl);

    wp_enqueue_style(
        'hc-dorb-registration',
        HCDR_PLUGIN_URL . 'assets/css/hc-dorb-registration.css',
        [],
        HCDR_VERSION
    );

    $isSuccess = $feedback['status'] === 'success';
    $errorMessage = $feedback['status'] === 'error' ? $feedback['message'] : '';

    $provinces = hcdr_get_canadian_provinces();

    $assets = [
        'step1' => 'http://localhost/wordpress/wp-content/uploads/2026/06/dorb-header.webp',
        'samplits_logo' => HCDR_PLUGIN_URL . 'assets/images/babybrands-logo.png',
        'thankyou_banner' => HCDR_PLUGIN_URL . 'assets/images/distributors-banner-thankyou.webp',
    ];

    ob_start();
    ?>
    <main class="hcdr-shell">
        <div class="hcdr-container">
            <?php if ($isSuccess) : ?>
                <div class="hcdr-thankyou hcdr-thankyou-page">
                    <div class="hcdr-thankyou-card">
                        <div class="hcdr-thankyou-badge" style="text-transform: uppercase;"><?php esc_html_e('Babybrands Healthcare Centres Free Sample Pads Registration Received', 'hc-dorb-registration'); ?></div>
                        <h1 class="hcdr-thankyou-card-title"><?php esc_html_e('Thank You!', 'hc-dorb-registration'); ?></h1>
                        <p class="hcdr-thankyou-card-lead"><?php esc_html_e('We have received your form.', 'hc-dorb-registration'); ?></p>
                        <div class="hcdr-thankyou-banner-wrap text-center">
                            <img class="hcdr-thankyou-banner" src="<?php echo esc_url($assets['thankyou_banner']); ?>" alt="">
                        </div>
                        <p class="hcdr-thankyou-card-text">
                            <?php esc_html_e('We will review your submission. If you have any questions, please contact', 'hc-dorb-registration'); ?>
                            <a href="mailto:<?php echo esc_attr($contactEmail); ?>"><?php echo esc_html($contactEmail); ?></a>
                        </p>
                        <a class="btn btn-primary hcdr-thankyou-cta" href="<?php echo esc_url($formPageUrl); ?>"><?php esc_html_e('Submit another form', 'hc-dorb-registration'); ?></a>
                        <div class="hcdr-thankyou-card-footer">
                            <div class="hcdr-powered-by"><?php esc_html_e('Powered by Samplits', 'hc-dorb-registration'); ?></div>
                            <img src="<?php echo esc_url($assets['samplits_logo']); ?>" alt="<?php esc_attr_e('Samplits Logo', 'hc-dorb-registration'); ?>">
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    try {
                        var u = new URL(window.location.href);
                        if (!u.searchParams.has('hcdr_status')) return;
                        u.searchParams.delete('hcdr_status');
                        u.searchParams.delete('hcdr_message');
                        var qs = u.searchParams.toString();
                        window.history.replaceState(null, '', u.pathname + (qs ? '?' + qs : '') + u.hash);
                    } catch (e) {}
                })();
                </script>
            <?php elseif ($errorMessage !== '') : ?>
                <div class="alert alert-danger text-center mb-4">
                    <?php echo esc_html($errorMessage); ?>
                </div>
                <script>
                (function () {
                    try {
                        var u = new URL(window.location.href);
                        if (!u.searchParams.has('hcdr_status') && !u.searchParams.has('hcdr_message')) return;
                        u.searchParams.delete('hcdr_status');
                        u.searchParams.delete('hcdr_message');
                        var qs = u.searchParams.toString();
                        window.history.replaceState(null, '', u.pathname + (qs ? '?' + qs : '') + u.hash);
                    } catch (e) {}
                })();
                </script>
            <?php endif; ?>

            <?php if (!$isSuccess) : ?>
                <div class="hcdr-form-container">
                    <div class="p-3 pb-0">
                        <img class="hcdr-header-image" src="<?php echo esc_url($assets['step1']); ?>" alt="">
                    </div>

                    <div class="hcdr-topbar">
                        <div class="hcdr-title" style="text-transform: uppercase;"><?php esc_html_e('Babybrands Healthcare Centres Free Sample Pads Registration', 'hc-dorb-registration'); ?></div>
                    </div>

                    <form class="hcdr-form px-3 pb-3 mt-3" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" novalidate>
                        <input type="hidden" name="action" value="<?php echo esc_attr(HCDR_POST_ACTION); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url($redirectUrl); ?>">
                        <input type="hidden" name="hcdr_success_redirect" value="<?php echo esc_url($successRedirectUrl); ?>">
                        <input type="hidden" name="hcdr_notification_email" value="<?php echo esc_attr($notifyEmail); ?>">
                        <?php wp_nonce_field(HCDR_POST_ACTION, 'hcdr_nonce'); ?>

                        <h3 class="h6 font-weight-bold mb-3 hcdr-section-title"><?php esc_html_e('General Information', 'hc-dorb-registration'); ?></h3>

                        <div class="form-group">
                            <label class="font-weight-bold d-block" for="hcdr-name">*<?php esc_html_e('Name of the organization', 'hc-dorb-registration'); ?></label>
                            <input type="text" class="form-control" id="hcdr-name" name="name" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-firstName">*<?php esc_html_e('Contact First Name', 'hc-dorb-registration'); ?></label>
                                    <input type="text" class="form-control" id="hcdr-firstName" name="firstName" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-lastName">*<?php esc_html_e('Contact Last Name', 'hc-dorb-registration'); ?></label>
                                    <input type="text" class="form-control" id="hcdr-lastName" name="lastName" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-email">*<?php esc_html_e('Email Address', 'hc-dorb-registration'); ?></label>
                                    <input type="email" class="form-control" id="hcdr-email" name="email" required>
                                    <div class="invalid-feedback" id="hcdr-emailError" style="display:none;"><?php esc_html_e('Invalid email address', 'hc-dorb-registration'); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-job">*<?php esc_html_e('Job Title', 'hc-dorb-registration'); ?></label>
                                    <input type="text" class="form-control" id="hcdr-job" name="job" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold d-block" for="hcdr-department">*<?php esc_html_e('Department/Unit', 'hc-dorb-registration'); ?></label>
                            <input type="text" class="form-control" id="hcdr-department" name="department" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-phone">*<?php esc_html_e('Telephone Number', 'hc-dorb-registration'); ?></label>
                                    <input type="text" class="form-control" id="hcdr-phone" name="phone" required>
                                    <div class="invalid-feedback" id="hcdr-phoneError" style="display:none;"><?php esc_html_e('Please enter a valid phone number', 'hc-dorb-registration'); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-extension"><?php esc_html_e('Extension', 'hc-dorb-registration'); ?></label>
                                    <input type="text" class="form-control" id="hcdr-extension" name="extension">
                                    <div class="invalid-feedback" id="hcdr-extensionError" style="display:none;"><?php esc_html_e('Please enter numbers only', 'hc-dorb-registration'); ?></div>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <h3 class="h6 font-weight-bold mb-3 hcdr-section-title"><?php esc_html_e('Shipping Information', 'hc-dorb-registration'); ?></h3>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-address1">*<?php esc_html_e('Address 1', 'hc-dorb-registration'); ?></label>
                                    <input type="text" class="form-control" id="hcdr-address1" name="address1" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-suite"><?php esc_html_e('Suite #', 'hc-dorb-registration'); ?></label>
                                    <input type="text" class="form-control" id="hcdr-suite" name="suite">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-city">*<?php esc_html_e('City', 'hc-dorb-registration'); ?></label>
                                    <input type="text" class="form-control" id="hcdr-city" name="city" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-province">*<?php esc_html_e('Province', 'hc-dorb-registration'); ?></label>
                                    <select class="form-control" id="hcdr-province" name="province" required>
                                        <option value=""><?php esc_html_e('Select Province', 'hc-dorb-registration'); ?></option>
                                        <?php foreach ($provinces as $abbr => $label) : ?>
                                            <option value="<?php echo esc_attr($abbr); ?>"><?php echo esc_html($abbr . ' - ' . $label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hcdr-postalCode">*<?php esc_html_e('Postal Code', 'hc-dorb-registration'); ?></label>
                                    <input type="text" class="form-control" id="hcdr-postalCode" name="postalCode" required>
                                    <div class="invalid-feedback" id="hcdr-postalCodeError" style="display:none;"><?php esc_html_e('Format: XNX NXN', 'hc-dorb-registration'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mt-3">
                            <label class="font-weight-bold d-block">*<?php esc_html_e('Category', 'hc-dorb-registration'); ?></label>
                            <?php foreach (hcdr_get_registration_categories() as $cat) :
                                $id = 'hcdr-category-' . sanitize_title($cat);
                                ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="category" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($cat); ?>" required>
                                    <label class="form-check-label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($cat); ?></label>
                                </div>
                            <?php endforeach; ?>
                            <div class="form-group mt-2 hcdr-category-other" style="display:none;">
                                <label class="font-weight-bold d-block" for="hcdr-categoryOther"><?php esc_html_e('Other (please specify)', 'hc-dorb-registration'); ?></label>
                                <input type="text" class="form-control hcdr-small-text-input" id="hcdr-categoryOther" name="categoryOther" maxlength="255">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold d-block">*<?php esc_html_e('Identify the majority of patients you cater to', 'hc-dorb-registration'); ?></label>
                            <?php foreach (hcdr_get_patients_types() as $pt) : ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="patientsType" id="hcdr-patientsType-<?php echo esc_attr(strtolower($pt)); ?>" value="<?php echo esc_attr($pt); ?>" required>
                                    <label class="form-check-label" for="hcdr-patientsType-<?php echo esc_attr(strtolower($pt)); ?>"><?php echo esc_html($pt); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="hcdr-late-fields">
                            <div class="form-group">
                                <div class="hcdr-inline-label-input">
                                    <label class="font-weight-bold hcdr-late-fields-top-label mb-0" for="hcdr-weeklyExpecting">*<?php esc_html_e('Approximately how many expecting parents do you see on a weekly basis?', 'hc-dorb-registration'); ?></label>
                                    <input type="text" class="form-control hcdr-inline-small-input" id="hcdr-weeklyExpecting" name="weeklyExpecting" required inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                                </div>
                                <div class="invalid-feedback" id="hcdr-weeklyExpectingError" style="display:none;"><?php esc_html_e('Please enter a whole number.', 'hc-dorb-registration'); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="font-weight-bold d-block">*<?php esc_html_e('Would you like to receive sample of Diapers, Sudocrem, wipes for your patients', 'hc-dorb-registration'); ?></label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="receivePatientSamples" id="hcdr-receive-yes" value="Yes" required>
                                    <label class="form-check-label" for="hcdr-receive-yes"><?php esc_html_e('Yes', 'hc-dorb-registration'); ?></label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="receivePatientSamples" id="hcdr-receive-no" value="No" required>
                                    <label class="form-check-label" for="hcdr-receive-no"><?php esc_html_e('No', 'hc-dorb-registration'); ?></label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="font-weight-bold d-block" for="hcdr-comments"><?php esc_html_e('Comments', 'hc-dorb-registration'); ?></label>
                                <textarea class="form-control" id="hcdr-comments" name="comments" rows="3" maxlength="1000"></textarea>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary"><?php esc_html_e('Register', 'hc-dorb-registration'); ?></button>
                        </div>
                    </form>

                    <div class="hcdr-footer p-4 hcdr-thankyou-card-footer">
                        <div class="hcdr-powered-by"><?php esc_html_e('Powered by Samplits', 'hc-dorb-registration'); ?></div>
                        <img src="<?php echo esc_url($assets['samplits_logo']); ?>" alt="<?php esc_attr_e('Samplits Logo', 'hc-dorb-registration'); ?>">
                    </div>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var form = document.querySelector('.hcdr-form');
                    if (!form || form.dataset.hcdrReady === '1') return;
                    form.dataset.hcdrReady = '1';

                    var catOtherWrap = form.querySelector('.hcdr-category-other');
                    var catOtherInput = document.getElementById('hcdr-categoryOther');
                    function updateCategoryOther() {
                        var selected = form.querySelector('input[name="category"]:checked');
                        var isOther = selected && selected.value === 'Other';
                        if (catOtherWrap) catOtherWrap.style.display = isOther ? 'block' : 'none';
                        if (catOtherInput) {
                            if (isOther) {
                                catOtherInput.setAttribute('required', 'required');
                            } else {
                                catOtherInput.removeAttribute('required');
                                catOtherInput.value = '';
                            }
                        }
                    }
                    form.addEventListener('change', function (e) {
                        if (e && e.target && e.target.name === 'category') updateCategoryOther();
                    });
                    updateCategoryOther();

                    function formatPhone(value) {
                        if (!value) return value;
                        var digits = value.replace(/\D/g, '').slice(0, 10);
                        if (digits.length <= 3) return digits;
                        if (digits.length <= 6) return '(' + digits.slice(0, 3) + ') ' + digits.slice(3);
                        return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
                    }

                    var phone = document.getElementById('hcdr-phone');
                    if (phone) {
                        phone.addEventListener('input', function () {
                            phone.value = formatPhone(phone.value);
                            syncFieldVisual(phone, false);
                        });
                    }

                    function hideErr(id) {
                        var n = document.getElementById(id);
                        if (n) n.style.display = 'none';
                    }
                    function showErr(id) {
                        var n = document.getElementById(id);
                        if (n) n.style.display = 'block';
                    }

                    function syncFieldVisual(el, isBlur) {
                        if (!el || !form.contains(el)) return;
                        if (el.type === 'submit' || el.type === 'button') return;

                        if (el.type === 'checkbox') {
                            if (el.required) {
                                el.classList.toggle('is-valid', el.checked);
                                el.classList.toggle('is-invalid', !el.checked);
                            }
                            return;
                        }

                        if (el.type === 'radio' && el.name) {
                            var esc = el.name.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
                            var radios = form.querySelectorAll('input[type="radio"][name="' + esc + '"]');
                            if (!radios.length) return;
                            var tracked = ['category', 'patientsType', 'receivePatientSamples'];
                            var req = false;
                            radios.forEach(function (r) { if (r.required) req = true; });
                            if (!req && tracked.indexOf(el.name) === -1) return;
                            var checked = form.querySelector('input[type="radio"][name="' + esc + '"]:checked');
                            var ok = !!checked;
                            radios.forEach(function (r) {
                                r.classList.remove('is-valid', 'is-invalid');
                                if (ok && r.checked) r.classList.add('is-valid');
                                if (!ok) r.classList.add('is-invalid');
                            });
                            return;
                        }

                        if (el.tagName !== 'INPUT' && el.tagName !== 'SELECT' && el.tagName !== 'TEXTAREA') return;

                        var v = (el.value || '').trim();
                        var id = el.id;

                        if (el.required && v === '') {
                            el.classList.remove('is-valid');
                            if (isBlur) el.classList.add('is-invalid');
                            else el.classList.remove('is-invalid');
                            if (id === 'hcdr-email') hideErr('hcdr-emailError');
                            if (id === 'hcdr-phone') hideErr('hcdr-phoneError');
                            if (id === 'hcdr-extension') hideErr('hcdr-extensionError');
                            if (id === 'hcdr-postalCode') hideErr('hcdr-postalCodeError');
                            if (id === 'hcdr-weeklyExpecting') hideErr('hcdr-weeklyExpectingError');
                            return;
                        }

                        var ok = true;
                        if (id === 'hcdr-email') {
                            ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
                            if (!ok) showErr('hcdr-emailError'); else hideErr('hcdr-emailError');
                        } else if (id === 'hcdr-phone') {
                            ok = /^\(\d{3}\) \d{3}-\d{4}$/.test(v);
                            if (!ok) showErr('hcdr-phoneError'); else hideErr('hcdr-phoneError');
                        } else if (id === 'hcdr-extension') {
                            ok = v === '' || /^[0-9]+$/.test(v);
                            if (!ok) showErr('hcdr-extensionError'); else hideErr('hcdr-extensionError');
                        } else if (id === 'hcdr-postalCode') {
                            ok = /^[A-Za-z]\d[A-Za-z] \d[A-Za-z]\d$/.test(v);
                            if (!ok) showErr('hcdr-postalCodeError'); else hideErr('hcdr-postalCodeError');
                        } else if (id === 'hcdr-weeklyExpecting') {
                            ok = /^[0-9]+$/.test(v);
                            if (!ok) showErr('hcdr-weeklyExpectingError'); else hideErr('hcdr-weeklyExpectingError');
                        }

                        if (!ok) {
                            el.classList.remove('is-valid');
                            el.classList.add('is-invalid');
                            return;
                        }
                        el.classList.remove('is-invalid');
                        el.classList.add('is-valid');
                    }

                    form.addEventListener('input', function (e) {
                        if (form.contains(e.target)) syncFieldVisual(e.target, false);
                    });
                    form.addEventListener('change', function (e) {
                        if (form.contains(e.target)) syncFieldVisual(e.target, true);
                    });
                    form.addEventListener('blur', function (e) {
                        if (form.contains(e.target)) syncFieldVisual(e.target, true);
                    }, true);

                    function validateForm() {
                        var isValid = true;
                        var required = form.querySelectorAll('[required]');
                        required.forEach(function (el) {
                            if (el.type === 'checkbox') {
                                el.classList.toggle('is-invalid', !el.checked);
                                el.classList.toggle('is-valid', el.checked);
                                if (!el.checked) isValid = false;
                                return;
                            }
                            if (el.type === 'radio') {
                                var group = form.querySelectorAll('input[type="radio"][name="' + el.name.replace(/"/g, '\\"') + '"]');
                                var anyChecked = false;
                                group.forEach(function (g) { if (g.checked) anyChecked = true; });
                                group.forEach(function (g) {
                                    g.classList.toggle('is-invalid', !anyChecked);
                                    g.classList.toggle('is-valid', anyChecked && g.checked);
                                });
                                if (!anyChecked) isValid = false;
                                return;
                            }
                            if (el.value === '') {
                                el.classList.add('is-invalid');
                                el.classList.remove('is-valid');
                                isValid = false;
                            } else {
                                el.classList.remove('is-invalid');
                                el.classList.add('is-valid');
                            }
                        });

                        var email = document.getElementById('hcdr-email');
                        var emailErr = document.getElementById('hcdr-emailError');
                        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value || '')) {
                            isValid = false;
                            email.classList.add('is-invalid');
                            email.classList.remove('is-valid');
                            if (emailErr) emailErr.style.display = 'block';
                        } else if (email) {
                            email.classList.remove('is-invalid');
                            email.classList.add('is-valid');
                            if (emailErr) emailErr.style.display = 'none';
                        }

                        var phoneEl = document.getElementById('hcdr-phone');
                        var phoneErr = document.getElementById('hcdr-phoneError');
                        if (phoneEl && !/^\(\d{3}\) \d{3}-\d{4}$/.test(phoneEl.value || '')) {
                            isValid = false;
                            phoneEl.classList.add('is-invalid');
                            phoneEl.classList.remove('is-valid');
                            if (phoneErr) phoneErr.style.display = 'block';
                        } else if (phoneEl) {
                            phoneEl.classList.remove('is-invalid');
                            phoneEl.classList.add('is-valid');
                            if (phoneErr) phoneErr.style.display = 'none';
                        }

                        var extEl = document.getElementById('hcdr-extension');
                        var extErr = document.getElementById('hcdr-extensionError');
                        if (extEl && extEl.value !== '' && !/^[0-9]+$/.test(extEl.value)) {
                            isValid = false;
                            extEl.classList.add('is-invalid');
                            extEl.classList.remove('is-valid');
                            if (extErr) extErr.style.display = 'block';
                        } else if (extEl) {
                            extEl.classList.remove('is-invalid');
                            extEl.classList.add('is-valid');
                            if (extErr) extErr.style.display = 'none';
                        }

                        var postal = document.getElementById('hcdr-postalCode');
                        var postalErr = document.getElementById('hcdr-postalCodeError');
                        if (postal && !/^[A-Za-z]\d[A-Za-z] \d[A-Za-z]\d$/.test((postal.value || '').trim())) {
                            isValid = false;
                            postal.classList.add('is-invalid');
                            postal.classList.remove('is-valid');
                            if (postalErr) postalErr.style.display = 'block';
                        } else if (postal) {
                            postal.classList.remove('is-invalid');
                            postal.classList.add('is-valid');
                            if (postalErr) postalErr.style.display = 'none';
                        }

                        var weekly = document.getElementById('hcdr-weeklyExpecting');
                        var weeklyErr = document.getElementById('hcdr-weeklyExpectingError');
                        if (weekly && !/^[0-9]+$/.test((weekly.value || '').trim())) {
                            isValid = false;
                            weekly.classList.add('is-invalid');
                            weekly.classList.remove('is-valid');
                            if (weeklyErr) weeklyErr.style.display = 'block';
                        } else if (weekly) {
                            weekly.classList.remove('is-invalid');
                            weekly.classList.add('is-valid');
                            if (weeklyErr) weeklyErr.style.display = 'none';
                        }

                        return isValid;
                    }

                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        if (!validateForm()) {
                            var c = document.querySelector('.hcdr-container');
                            if (c) window.scrollTo({ top: c.getBoundingClientRect().top + window.scrollY - 40, behavior: 'smooth' });
                            return;
                        }
                        if (typeof form.submit === 'function') form.submit();
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </main>
    <?php

    return ob_get_clean();
}

add_shortcode('hc_dorb_registration', 'hcdr_render_form_shortcode');

function hcdr_handle_submission()
{
    $redirectUrl = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : home_url('/');
    if ($redirectUrl === '') {
        $redirectUrl = home_url('/');
    }

    $successRedirect = isset($_POST['hcdr_success_redirect'])
        ? esc_url_raw(wp_unslash($_POST['hcdr_success_redirect']))
        : $redirectUrl;
    if ($successRedirect === '') {
        $successRedirect = $redirectUrl;
    }
    $successRedirect = wp_validate_redirect($successRedirect, $redirectUrl);
    $successRedirect = remove_query_arg(['hcdr_status', 'hcdr_message'], $successRedirect);

    $posted_action = isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : '';
    $nonce_by_action = [
        HCDR_POST_ACTION => HCDR_POST_ACTION,
        'hcdr_submit_registration' => 'hcdr_submit_registration',
    ];
    $nonce_action = isset($nonce_by_action[$posted_action]) ? $nonce_by_action[$posted_action] : HCDR_POST_ACTION;
    if (
        !isset($_POST['hcdr_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hcdr_nonce'])), $nonce_action)
    ) {
        wp_safe_redirect(hcdr_build_feedback_url('error', __('Security check failed. Please try again.', 'hc-dorb-registration'), $redirectUrl));
        exit;
    }
    global $wpdb;

    $input = [
        'healthcare_id' => '',
        'organization_name' => wp_unslash($_POST['name'] ?? ''),
        'contact_first_name' => wp_unslash($_POST['firstName'] ?? ''),
        'contact_last_name' => wp_unslash($_POST['lastName'] ?? ''),
        'email' => wp_unslash($_POST['email'] ?? ''),
        'job_title' => wp_unslash($_POST['job'] ?? ''),
        'department' => wp_unslash($_POST['department'] ?? ''),
        'phone' => wp_unslash($_POST['phone'] ?? ''),
        'extension' => wp_unslash($_POST['extension'] ?? ''),
        'address_1' => wp_unslash($_POST['address1'] ?? ''),
        'suite' => wp_unslash($_POST['suite'] ?? ''),
        'city' => wp_unslash($_POST['city'] ?? ''),
        'province' => wp_unslash($_POST['province'] ?? ''),
        'postal_code' => wp_unslash($_POST['postalCode'] ?? ''),
        'category' => wp_unslash($_POST['category'] ?? ''),
        'category_other' => wp_unslash($_POST['categoryOther'] ?? ''),
        'patients_type' => wp_unslash($_POST['patientsType'] ?? ''),
        'weekly_expecting_parents' => wp_unslash($_POST['weeklyExpecting'] ?? ''),
        'receive_patient_samples' => wp_unslash($_POST['receivePatientSamples'] ?? ''),
        'comments' => wp_unslash($_POST['comments'] ?? ''),
    ];

    $validated = hcdr_validate_submission_row($input, true);
    if (is_wp_error($validated)) {
        wp_safe_redirect(hcdr_build_feedback_url('error', $validated->get_error_message(), $redirectUrl));
        exit;
    }

    $table = hcdr_get_submissions_table_name();
    $validated['created_at'] = hcdr_created_at_mysql();
    $inserted = $wpdb->insert(
        $table,
        $validated,
        [
            '%s', // healthcare_id
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s', '%s',
        ]
    );

    if (!$inserted) {
        wp_safe_redirect(hcdr_build_feedback_url('error', __('Could not save your submission. Please try again later.', 'hc-dorb-registration'), $redirectUrl));
        exit;
    }

    $notify = sanitize_email(wp_unslash($_POST['hcdr_notification_email'] ?? ''));
    if ($notify !== '' && is_email($notify) && apply_filters('hcdr_send_notification_email', true, $wpdb->insert_id)) {
        $subject = sprintf('[%s] New HC DORB Registration', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $body = sprintf(
            "Organization: %s\nContact: %s %s\nEmail: %s\nPhone: %s\nAddress: %s, %s, %s %s\nCategory: %s\nCategory (other): %s\nPatients: %s\nExpecting parents (weekly): %s\nReceive patient samples: %s\nComments: %s\n",
            $validated['organization_name'],
            $validated['contact_first_name'],
            $validated['contact_last_name'],
            $validated['email'],
            $validated['phone'],
            $validated['address_1'],
            $validated['city'],
            $validated['province'],
            $validated['postal_code'],
            $validated['category'],
            isset($validated['category_other']) && $validated['category_other'] !== null ? $validated['category_other'] : '',
            $validated['patients_type'],
            $validated['weekly_expecting_parents'],
            $validated['receive_patient_samples'],
            isset($validated['comments']) && $validated['comments'] !== null ? $validated['comments'] : ''
        );
        wp_mail($notify, $subject, $body);
    }

    wp_safe_redirect(hcdr_build_feedback_url('success', '', $successRedirect));
    exit;
}

add_action('admin_post_nopriv_' . HCDR_POST_ACTION, 'hcdr_handle_submission');
add_action('admin_post_' . HCDR_POST_ACTION, 'hcdr_handle_submission');
/** @deprecated Prior action name; kept for cached HTML. */
add_action('admin_post_nopriv_hcdr_submit_registration', 'hcdr_handle_submission');
add_action('admin_post_hcdr_submit_registration', 'hcdr_handle_submission');

function hcdr_register_admin_menu()
{
    add_menu_page(
        __('HC DORB Registrations', 'hc-dorb-registration'),
        __('HC DORB Registrations', 'hc-dorb-registration'),
        'manage_options',
        HCDR_ADMIN_PAGE_SLUG,
        'hcdr_render_admin_submissions_page',
        'dashicons-clipboard',
        26
    );
}

add_action('admin_menu', 'hcdr_register_admin_menu');

add_action('admin_head', static function () {
    if (empty($_GET['page']) || sanitize_key(wp_unslash((string) $_GET['page'])) !== HCDR_ADMIN_PAGE_SLUG) {
        return;
    }
    ?>
    <style>
        /* Keep the "No." column compact on submissions list table. */
        .wp-list-table .column-hcdr_no { width: 30px; }
        .wp-list-table .column-hcdr_no strong { display: inline-block; min-width: 0; }
    </style>
    <?php
});

function hcdr_maybe_export_submissions_csv()
{
    if (empty($_GET['page']) || sanitize_key(wp_unslash((string) $_GET['page'])) !== HCDR_ADMIN_PAGE_SLUG) {
        return;
    }
    if (empty($_GET['hcdr_export']) || sanitize_key(wp_unslash((string) $_GET['hcdr_export'])) !== 'csv') {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'hc-dorb-registration'));
    }

    check_admin_referer('hcdr_export_csv');

    global $wpdb;

    $table = hcdr_get_submissions_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        wp_die(esc_html__('Submissions table is not available.', 'hc-dorb-registration'));
    }

    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
    $cond = hcdr_submissions_search_where_clause($wpdb, $search);

    $sql = "SELECT * FROM `{$table}`";
    if ($cond['sql'] !== '') {
        $sql .= ' WHERE ' . $cond['sql'];
    }
    $sql .= ' ORDER BY `created_at` DESC, `id` DESC';

    $rows = $cond['args'] === []
        ? $wpdb->get_results($sql, ARRAY_A)
        : $wpdb->get_results($wpdb->prepare($sql, ...$cond['args']), ARRAY_A);

    if (!is_array($rows)) {
        $rows = [];
    }

    $columns = hcdr_submissions_csv_columns();
    $filename = 'hc-dorb-registration-submissions-' . gmdate('Y-m-d') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }

    // Excel on Windows detects UTF-8 reliably when a BOM is present.
    // (Some plain-text viewers may show it as "ï»¿" if opened with the wrong encoding.)

    fputcsv($out, $columns);

    $no = 0;
    foreach ($rows as $row) {
        $no++;
        $line = [];
        foreach ($columns as $col) {
            if ($col === 'no') {
                $line[] = (string) $no;
                continue;
            }

            $val = isset($row[$col]) && $row[$col] !== null ? (string) $row[$col] : '';
            $line[] = hcdr_csv_normalize_value($val);
        }
        fputcsv($out, $line);
    }

    fclose($out);
    exit;
}

add_action('admin_init', 'hcdr_maybe_export_submissions_csv', 1);

function hcdr_render_admin_submissions_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'hc-dorb-registration'));
    }

    if (!empty($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
        $editId = absint($_GET['id']);
        if ($editId > 0) {
            hcdr_render_submission_edit($editId);

            return;
        }
    }

    if (!empty($_GET['view'])) {
        $viewId = absint($_GET['view']);
        if ($viewId > 0) {
            hcdr_render_submission_detail($viewId);

            return;
        }
    }

    $list_table = new HCDR_Submissions_List_Table();
    $list_table->prepare_items();

    $notice = isset($_GET['hcdr_notice']) ? sanitize_key(wp_unslash($_GET['hcdr_notice'])) : '';
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Babybrands Healthcare Centres Free Sample Pads Registration Submissions', 'hc-dorb-registration'); ?></h1>
        <hr class="wp-header-end">
        <?php if ($notice === 'saved') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Submission updated.', 'hc-dorb-registration'); ?></p></div>
        <?php elseif ($notice === 'deleted') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Submission deleted.', 'hc-dorb-registration'); ?></p></div>
        <?php elseif ($notice === 'error') : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(isset($_GET['hcdr_err']) ? sanitize_text_field(wp_unslash((string) $_GET['hcdr_err'])) : __('Something went wrong.', 'hc-dorb-registration')); ?></p></div>
        <?php endif; ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr(HCDR_ADMIN_PAGE_SLUG); ?>">
            <?php if (!empty($_GET['orderby'])) : ?>
                <input type="hidden" name="orderby" value="<?php echo esc_attr(sanitize_key(wp_unslash((string) $_GET['orderby']))); ?>">
            <?php endif; ?>
            <?php if (!empty($_GET['order'])) : ?>
                <input type="hidden" name="order" value="<?php echo esc_attr(strtoupper(sanitize_text_field(wp_unslash((string) $_GET['order']))) === 'ASC' ? 'ASC' : 'DESC'); ?>">
            <?php endif; ?>
            <?php $list_table->display(); ?>
        </form>
    </div>
    <?php
}

/**
 * @param int $id Submission primary key.
 */
function hcdr_render_submission_detail($id)
{
    global $wpdb;

    $table = hcdr_get_submissions_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        echo '<div class="wrap"><p>' . esc_html__('Submissions table is not available.', 'hc-dorb-registration') . '</p></div>';

        return;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
    if (!$row) {
        echo '<div class="wrap"><p>' . esc_html__('Submission not found.', 'hc-dorb-registration') . '</p></div>';

        return;
    }

    $hcdr_no = isset($_GET['hcdr_no']) ? absint(wp_unslash($_GET['hcdr_no'])) : 0;

    $back_url = add_query_arg(['page' => HCDR_ADMIN_PAGE_SLUG], admin_url('admin.php'));
    $edit_url = add_query_arg(
        array_filter(
            [
                'page' => HCDR_ADMIN_PAGE_SLUG,
                'action' => 'edit',
                'id' => (int) $row->id,
                'hcdr_no' => $hcdr_no > 0 ? $hcdr_no : null,
            ]
        ),
        admin_url('admin.php')
    );
    $delete_url = wp_nonce_url(
        add_query_arg(
            [
                'action' => 'hcdr_delete_submission',
                'id' => (int) $row->id,
            ],
            admin_url('admin-post.php')
        ),
        'hcdr_delete_submission_' . (int) $row->id
    );
    $confirm_js = wp_json_encode(__('Delete this submission? This cannot be undone.', 'hc-dorb-registration'));

    $fields = [
        'healthcare_id' => __('Healthcare ID', 'hc-dorb-registration'),
        'organization_name' => __('Organization', 'hc-dorb-registration'),
        'contact_first_name' => __('Contact first name', 'hc-dorb-registration'),
        'contact_last_name' => __('Contact last name', 'hc-dorb-registration'),
        'email' => __('Email', 'hc-dorb-registration'),
        'job_title' => __('Job title', 'hc-dorb-registration'),
        'department' => __('Department', 'hc-dorb-registration'),
        'phone' => __('Phone', 'hc-dorb-registration'),
        'extension' => __('Extension', 'hc-dorb-registration'),
        'address_1' => __('Address', 'hc-dorb-registration'),
        'suite' => __('Suite', 'hc-dorb-registration'),
        'city' => __('City', 'hc-dorb-registration'),
        'province' => __('Province', 'hc-dorb-registration'),
        'postal_code' => __('Postal code', 'hc-dorb-registration'),
        'category' => __('Category', 'hc-dorb-registration'),
        'category_other' => __('Category (other)', 'hc-dorb-registration'),
        'patients_type' => __('Patients type', 'hc-dorb-registration'),
        'weekly_expecting_parents' => __('Expecting parents (weekly)', 'hc-dorb-registration'),
        'receive_patient_samples' => __('Receive patient samples (Diapers, Sudocrem, wipes)', 'hc-dorb-registration'),
        'comments' => __('Comments', 'hc-dorb-registration'),
        'created_at' => __('Submitted', 'hc-dorb-registration'),
    ];
    ?>
    <div class="wrap hcdr-submission-detail">
        <h1><?php echo $hcdr_no > 0 ? esc_html(sprintf(__('No. %d', 'hc-dorb-registration'), $hcdr_no)) : esc_html__('Submission', 'hc-dorb-registration'); ?></h1>
        <?php if (!empty($_GET['hcdr_notice']) && sanitize_key(wp_unslash($_GET['hcdr_notice'])) === 'saved') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Submission updated.', 'hc-dorb-registration'); ?></p></div>
        <?php endif; ?>
        <p><a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to list', 'hc-dorb-registration'); ?></a></p>
        <table class="widefat striped" style="max-width:920px;">
            <tbody>
            <?php foreach ($fields as $key => $label) : ?>
                <tr>
                    <th scope="row" style="width:220px;"><?php echo esc_html($label); ?></th>
                    <td>
                        <?php
                        $val = isset($row->$key) ? (string) $row->$key : '';
                        if ($key === 'email' && $val !== '') {
                            echo '<a href="' . esc_url('mailto:' . $val) . '">' . esc_html($val) . '</a>';
                        } elseif ($key === 'created_at' && $val !== '') {
                            $ts = hcdr_created_at_to_timestamp($val);
                            echo $ts ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts, hcdr_created_at_timezone())) : esc_html($val);
                        } elseif ($key === 'comments' && $val !== '') {
                            echo nl2br(esc_html($val));
                        } else {
                            echo $val !== '' ? esc_html($val) : '—';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="submit" style="max-width:920px;">
            <a class="button button-primary" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'hc-dorb-registration'); ?></a>
            <a class="button button-link-delete" href="<?php echo esc_url($delete_url); ?>" onclick="return window.confirm(<?php echo $confirm_js; ?>);"><?php esc_html_e('Delete', 'hc-dorb-registration'); ?></a>
        </p>
    </div>
    <?php
}

/**
 * @param int $id Submission primary key.
 */
function hcdr_render_submission_edit($id)
{
    global $wpdb;

    $table = hcdr_get_submissions_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        echo '<div class="wrap"><p>' . esc_html__('Submissions table is not available.', 'hc-dorb-registration') . '</p></div>';

        return;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
    if (!$row) {
        echo '<div class="wrap"><p>' . esc_html__('Submission not found.', 'hc-dorb-registration') . '</p></div>';

        return;
    }

    $hcdr_no = isset($_GET['hcdr_no']) ? absint(wp_unslash($_GET['hcdr_no'])) : 0;

    $back_list = add_query_arg(['page' => HCDR_ADMIN_PAGE_SLUG], admin_url('admin.php'));
    $back_view = add_query_arg(
        array_filter(
            [
                'page' => HCDR_ADMIN_PAGE_SLUG,
                'view' => (int) $row->id,
                'hcdr_no' => $hcdr_no > 0 ? $hcdr_no : null,
            ]
        ),
        admin_url('admin.php')
    );

    $v = static function ($key) use ($row) {
        return isset($row->$key) ? (string) $row->$key : '';
    };

    $provinces = hcdr_get_canadian_provinces();
    ?>
    <div class="wrap">
        <h1><?php echo $hcdr_no > 0 ? esc_html(sprintf(__('Edit — No. %d', 'hc-dorb-registration'), $hcdr_no)) : esc_html__('Edit submission', 'hc-dorb-registration'); ?></h1>
        <?php
        $admNotice = isset($_GET['hcdr_notice']) ? sanitize_key(wp_unslash($_GET['hcdr_notice'])) : '';
        if ($admNotice === 'error' && !empty($_GET['hcdr_err'])) :
            $errMsg = sanitize_text_field(wp_unslash((string) $_GET['hcdr_err']));
            ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($errMsg); ?></p></div>
        <?php endif; ?>
        <p>
            <a href="<?php echo esc_url($back_list); ?>">&larr; <?php esc_html_e('Back to list', 'hc-dorb-registration'); ?></a>
            &nbsp;|&nbsp;
            <a href="<?php echo esc_url($back_view); ?>"><?php esc_html_e('View detail', 'hc-dorb-registration'); ?></a>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hcdr-admin-edit-form">
            <?php wp_nonce_field('hcdr_save_submission_' . (int) $row->id); ?>
            <input type="hidden" name="action" value="hcdr_save_submission">
            <input type="hidden" name="submission_id" value="<?php echo (int) $row->id; ?>">
            <input type="hidden" name="hcdr_display_no" value="<?php echo $hcdr_no > 0 ? (int) $hcdr_no : ''; ?>">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="hcdr-adm-hc-id"><?php esc_html_e('Healthcare ID', 'hc-dorb-registration'); ?></label></th>
                    <td>
                        <?php $hcIdVal = $v('healthcare_id'); ?>
                        <input name="healthcare_id" id="hcdr-adm-hc-id" type="text" placeholder="HCP" class="regular-text" value="<?php echo esc_attr($hcIdVal !== '' ? $hcIdVal : ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-org"><?php esc_html_e('Organization', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="organization_name" id="hcdr-adm-org" type="text" class="regular-text" value="<?php echo esc_attr($v('organization_name')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-fn"><?php esc_html_e('Contact first name', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="contact_first_name" id="hcdr-adm-fn" type="text" class="regular-text" value="<?php echo esc_attr($v('contact_first_name')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-ln"><?php esc_html_e('Contact last name', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="contact_last_name" id="hcdr-adm-ln" type="text" class="regular-text" value="<?php echo esc_attr($v('contact_last_name')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-em"><?php esc_html_e('Email', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="email" id="hcdr-adm-em" type="email" class="regular-text" value="<?php echo esc_attr($v('email')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-job"><?php esc_html_e('Job title', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="job_title" id="hcdr-adm-job" type="text" class="regular-text" value="<?php echo esc_attr($v('job_title')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-dept"><?php esc_html_e('Department', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="department" id="hcdr-adm-dept" type="text" class="regular-text" value="<?php echo esc_attr($v('department')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-ph"><?php esc_html_e('Phone', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="phone" id="hcdr-adm-ph" type="text" class="regular-text" value="<?php echo esc_attr($v('phone')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-ext"><?php esc_html_e('Extension', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="extension" id="hcdr-adm-ext" type="text" class="regular-text" value="<?php echo esc_attr($v('extension')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-a1"><?php esc_html_e('Address', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="address_1" id="hcdr-adm-a1" type="text" class="regular-text" value="<?php echo esc_attr($v('address_1')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-suite"><?php esc_html_e('Suite', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="suite" id="hcdr-adm-suite" type="text" class="regular-text" value="<?php echo esc_attr($v('suite')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-city"><?php esc_html_e('City', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="city" id="hcdr-adm-city" type="text" class="regular-text" value="<?php echo esc_attr($v('city')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-prov"><?php esc_html_e('Province', 'hc-dorb-registration'); ?></label></th>
                    <td>
                        <select name="province" id="hcdr-adm-prov" required>
                            <option value=""><?php esc_html_e('Select…', 'hc-dorb-registration'); ?></option>
                            <?php foreach ($provinces as $abbr => $label) : ?>
                                <option value="<?php echo esc_attr($abbr); ?>" <?php selected($v('province'), $abbr); ?>><?php echo esc_html($abbr . ' — ' . $label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-postal"><?php esc_html_e('Postal code', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="postal_code" id="hcdr-adm-postal" type="text" class="regular-text" value="<?php echo esc_attr($v('postal_code')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Category', 'hc-dorb-registration'); ?></th>
                    <td>
                        <?php foreach (hcdr_get_registration_categories() as $cat) : ?>
                            <label style="display:inline-block;margin-right:12px;">
                                <input type="radio" name="category" value="<?php echo esc_attr($cat); ?>" <?php checked($v('category'), $cat); ?> required>
                                <?php echo esc_html($cat); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-cat-other"><?php esc_html_e('Category (other)', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="category_other" id="hcdr-adm-cat-other" type="text" class="regular-text" value="<?php echo esc_attr($v('category_other')); ?>" maxlength="255"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Patients type', 'hc-dorb-registration'); ?></th>
                    <td>
                        <?php foreach (hcdr_get_patients_types() as $pt) : ?>
                            <label style="display:inline-block;margin-right:12px;">
                                <input type="radio" name="patients_type" value="<?php echo esc_attr($pt); ?>" <?php checked($v('patients_type'), $pt); ?> required>
                                <?php echo esc_html($pt); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-weekly"><?php esc_html_e('Expecting parents (weekly)', 'hc-dorb-registration'); ?></label></th>
                    <td><input name="weekly_expecting_parents" id="hcdr-adm-weekly" type="text" class="small-text" value="<?php echo esc_attr($v('weekly_expecting_parents')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Receive patient samples', 'hc-dorb-registration'); ?></th>
                    <td>
                        <label><input type="radio" name="receive_patient_samples" value="Yes" <?php checked($v('receive_patient_samples'), 'Yes'); ?> required> <?php esc_html_e('Yes', 'hc-dorb-registration'); ?></label>
                        &nbsp;&nbsp;
                        <label><input type="radio" name="receive_patient_samples" value="No" <?php checked($v('receive_patient_samples'), 'No'); ?> required> <?php esc_html_e('No', 'hc-dorb-registration'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hcdr-adm-comments"><?php esc_html_e('Comments', 'hc-dorb-registration'); ?></label></th>
                    <td>
                        <textarea name="comments" id="hcdr-adm-comments" class="large-text" rows="4" maxlength="1000"><?php echo esc_textarea($v('comments')); ?></textarea>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save changes', 'hc-dorb-registration')); ?>
        </form>
    </div>
    <?php
}

function hcdr_handle_admin_delete_submission()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'hc-dorb-registration'));
    }

    $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
    if ($id < 1) {
        wp_safe_redirect(hcdr_admin_submissions_list_url(['hcdr_notice' => 'error', 'hcdr_err' => __('Invalid submission.', 'hc-dorb-registration')]));
        exit;
    }

    check_admin_referer('hcdr_delete_submission_' . $id);

    global $wpdb;
    $table = hcdr_get_submissions_table_name();
    $wpdb->delete($table, ['id' => $id], ['%d']);

    wp_safe_redirect(hcdr_admin_submissions_list_url(['hcdr_notice' => 'deleted']));
    exit;
}

function hcdr_handle_admin_save_submission()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'hc-dorb-registration'));
    }

    $id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
    $display_no = isset($_POST['hcdr_display_no']) ? absint(wp_unslash((string) $_POST['hcdr_display_no'])) : 0;
    if ($id < 1 || !isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'hcdr_save_submission_' . $id)) {
        wp_safe_redirect(hcdr_admin_submissions_list_url(['hcdr_notice' => 'error', 'hcdr_err' => __('Security check failed.', 'hc-dorb-registration')]));
        exit;
    }

    $input = [
        'healthcare_id' => wp_unslash($_POST['healthcare_id'] ?? ''),
        'organization_name' => wp_unslash($_POST['organization_name'] ?? ''),
        'contact_first_name' => wp_unslash($_POST['contact_first_name'] ?? ''),
        'contact_last_name' => wp_unslash($_POST['contact_last_name'] ?? ''),
        'email' => wp_unslash($_POST['email'] ?? ''),
        'job_title' => wp_unslash($_POST['job_title'] ?? ''),
        'department' => wp_unslash($_POST['department'] ?? ''),
        'phone' => wp_unslash($_POST['phone'] ?? ''),
        'extension' => wp_unslash($_POST['extension'] ?? ''),
        'address_1' => wp_unslash($_POST['address_1'] ?? ''),
        'suite' => wp_unslash($_POST['suite'] ?? ''),
        'city' => wp_unslash($_POST['city'] ?? ''),
        'province' => wp_unslash($_POST['province'] ?? ''),
        'postal_code' => wp_unslash($_POST['postal_code'] ?? ''),
        'category' => wp_unslash($_POST['category'] ?? ''),
        'category_other' => wp_unslash($_POST['category_other'] ?? ''),
        'patients_type' => wp_unslash($_POST['patients_type'] ?? ''),
        'weekly_expecting_parents' => wp_unslash($_POST['weekly_expecting_parents'] ?? ''),
        'receive_patient_samples' => wp_unslash($_POST['receive_patient_samples'] ?? ''),
        'comments' => wp_unslash($_POST['comments'] ?? ''),
    ];

    $validated = hcdr_validate_submission_row($input, true);
    if (is_wp_error($validated)) {
        wp_safe_redirect(
            hcdr_admin_submissions_list_url(
                array_filter(
                    [
                        'hcdr_notice' => 'error',
                        'hcdr_err' => $validated->get_error_message(),
                        'action' => 'edit',
                        'id' => $id,
                        'hcdr_no' => $display_no > 0 ? $display_no : null,
                    ]
                )
            )
        );
        exit;
    }

    global $wpdb;
    $table = hcdr_get_submissions_table_name();
    $wpdb->update(
        $table,
        $validated,
        ['id' => $id],
        [
            '%s', // healthcare_id
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s',
        ],
        ['%d']
    );

    if ($wpdb->last_error !== '') {
        wp_safe_redirect(
            hcdr_admin_submissions_list_url(
                array_filter(
                    [
                        'hcdr_notice' => 'error',
                        'hcdr_err' => __('Could not save changes.', 'hc-dorb-registration'),
                        'action' => 'edit',
                        'id' => $id,
                        'hcdr_no' => $display_no > 0 ? $display_no : null,
                    ]
                )
            )
        );
        exit;
    }

    wp_safe_redirect(
        add_query_arg(
            array_filter(
                [
                    'page' => HCDR_ADMIN_PAGE_SLUG,
                    'view' => $id,
                    'hcdr_notice' => 'saved',
                    'hcdr_no' => $display_no > 0 ? $display_no : null,
                ]
            ),
            admin_url('admin.php')
        )
    );
    exit;
}

/**
 * @param array<string,string> $args Query args merged onto admin list URL.
 */
function hcdr_admin_submissions_list_url(array $args = [])
{
    return add_query_arg(array_merge(['page' => HCDR_ADMIN_PAGE_SLUG], $args), admin_url('admin.php'));
}

add_action('admin_post_hcdr_delete_submission', 'hcdr_handle_admin_delete_submission');
add_action('admin_post_hcdr_save_submission', 'hcdr_handle_admin_save_submission');

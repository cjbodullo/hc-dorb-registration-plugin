<?php
/**
 * Admin list table for HC registration submissions (paginated).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class HCDR_Submissions_List_Table extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct(
            [
                'singular' => 'submission',
                'plural' => 'submissions',
                'ajax' => false,
            ]
        );
    }

    public function get_columns()
    {
        return [
            'hcdr_no' => __('No.', 'hc-dorb-registration'),
            'healthcare_id' => __('Healthcare ID', 'hc-dorb-registration'),
            'organization_name' => __('Organization', 'hc-dorb-registration'),
            'created_at' => __('Submitted', 'hc-dorb-registration'),
            'contact' => __('Contact', 'hc-dorb-registration'),
            'email' => __('Email', 'hc-dorb-registration'),
            'city' => __('City', 'hc-dorb-registration'),
            'category' => __('Category', 'hc-dorb-registration'),
            'patients_type' => __('Patients', 'hc-dorb-registration'),
            'receive_patient_samples' => __('Patient samples', 'hc-dorb-registration'),
            'hcdr_actions' => __('Actions', 'hc-dorb-registration'),
        ];
    }

    protected function get_sortable_columns()
    {
        return [
            'created_at' => ['created_at', true],
            'organization_name' => ['organization_name', false],
            'healthcare_id' => ['healthcare_id', false],
            'email' => ['email', false],
            'city' => ['city', false],
        ];
    }

    public function no_items()
    {
        esc_html_e('No submissions yet.', 'hc-dorb-registration');
    }

    protected function extra_tablenav($which)
    {
        if ($which !== 'top') {
            return;
        }

        $s = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['s'])) : '';
        $export_url = wp_nonce_url(
            add_query_arg(
                array_filter(
                    [
                        'page' => HCDR_ADMIN_PAGE_SLUG,
                        'hcdr_export' => 'csv',
                        's' => $s !== '' ? $s : null,
                    ]
                ),
                admin_url('admin.php')
            ),
            'hcdr_export_csv'
        );
        ?>
        <div class="alignleft actions hcdr-submissions-toolbar">
            <label class="screen-reader-text" for="hcdr-submissions-search"><?php esc_html_e('Search submissions', 'hc-dorb-registration'); ?></label>
            <input type="search" id="hcdr-submissions-search" class="regular-text" name="s" value="<?php echo esc_attr($s); ?>" placeholder="<?php esc_attr_e('Search…', 'hc-dorb-registration'); ?>">
            <?php submit_button(__('Search', 'hc-dorb-registration'), 'secondary', 'hcdr-submissions-search-submit', false); ?>
            <a href="<?php echo esc_url($export_url); ?>" class="button"><?php esc_html_e('Export CSV', 'hc-dorb-registration'); ?></a>
        </div>
        <?php
    }

    public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        global $wpdb;

        $table = hcdr_get_submissions_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            $this->items = [];
            $this->set_pagination_args(
                [
                    'total_items' => 0,
                    'per_page' => 20,
                    'total_pages' => 0,
                ]
            );

            return;
        }

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'created_at';
        $allowed = ['created_at', 'organization_name', 'healthcare_id', 'email', 'city', 'category'];
        if (!in_array($orderby, $allowed, true)) {
            $orderby = 'created_at';
        }

        $order = isset($_REQUEST['order']) ? strtoupper(sanitize_text_field(wp_unslash($_REQUEST['order']))) : 'DESC';
        if ($order !== 'ASC' && $order !== 'DESC') {
            $order = 'DESC';
        }

        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['s'])) : '';
        $search_cond = function_exists('hcdr_submissions_search_where_clause')
            ? hcdr_submissions_search_where_clause($wpdb, $search)
            : ['sql' => '', 'args' => []];

        if ($search_cond['sql'] !== '') {
            $total_items = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE " . $search_cond['sql'],
                    ...$search_cond['args']
                )
            );
        } else {
            $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        }

        $per_page = absint($per_page);
        $offset = absint($offset);

        $select_sql = "SELECT * FROM `{$table}`";
        if ($search_cond['sql'] !== '') {
            $select_sql .= ' WHERE ' . $search_cond['sql'];
        }
        $select_sql .= " ORDER BY `{$orderby}` {$order}, `id` DESC LIMIT %d OFFSET %d";

        if ($search_cond['args'] === []) {
            $this->items = $wpdb->get_results($wpdb->prepare($select_sql, $per_page, $offset));
        } else {
            $this->items = $wpdb->get_results(
                $wpdb->prepare($select_sql, ...array_merge($search_cond['args'], [$per_page, $offset]))
            );
        }

        if (is_array($this->items)) {
            foreach ($this->items as $i => $row) {
                $row->hcdr_row_no = $offset + (int) $i + 1;
            }
        }

        $this->set_pagination_args(
            [
                'total_items' => $total_items,
                'per_page' => $per_page,
                'total_pages' => (int) ceil($total_items / $per_page),
            ]
        );
    }

    protected function column_default($item, $column_name)
    {
        if (!isset($item->$column_name)) {
            return '';
        }

        return esc_html((string) $item->$column_name);
    }

    protected function column_hcdr_no($item)
    {
        return isset($item->hcdr_row_no) ? sprintf('<strong>%d</strong>', (int) $item->hcdr_row_no) : '';
    }

    protected function column_hcdr_actions($item)
    {
        $id = (int) $item->id;
        $no = isset($item->hcdr_row_no) ? (int) $item->hcdr_row_no : 0;
        $viewUrl = add_query_arg(
            array_filter(
                [
                    'page' => HCDR_ADMIN_PAGE_SLUG,
                    'view' => $id,
                    'hcdr_no' => $no > 0 ? $no : null,
                ]
            ),
            admin_url('admin.php')
        );
        $editUrl = add_query_arg(
            array_filter(
                [
                    'page' => HCDR_ADMIN_PAGE_SLUG,
                    'action' => 'edit',
                    'id' => $id,
                    'hcdr_no' => $no > 0 ? $no : null,
                ]
            ),
            admin_url('admin.php')
        );
        $deleteUrl = wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'hcdr_delete_submission',
                    'id' => $id,
                ],
                admin_url('admin-post.php')
            ),
            'hcdr_delete_submission_' . $id
        );
        $confirm = esc_js(__('Delete this submission? This cannot be undone.', 'hc-dorb-registration'));

        return sprintf(
            '<a class="button button-small" href="%1$s">%2$s</a> <a class="button button-small" href="%3$s">%4$s</a> <a class="button button-small button-link-delete" href="%5$s" onclick="return window.confirm(%6$s);">%7$s</a>',
            esc_url($viewUrl),
            esc_html__('View', 'hc-dorb-registration'),
            esc_url($editUrl),
            esc_html__('Edit', 'hc-dorb-registration'),
            esc_url($deleteUrl),
            wp_json_encode($confirm),
            esc_html__('Delete', 'hc-dorb-registration')
        );
    }

    protected function column_created_at($item)
    {
        if (empty($item->created_at)) {
            return '';
        }

        $ts = function_exists('hcdr_created_at_to_timestamp')
            ? hcdr_created_at_to_timestamp((string) $item->created_at)
            : strtotime((string) $item->created_at);

        if (!$ts) {
            return esc_html((string) $item->created_at);
        }

        $tz = function_exists('hcdr_created_at_timezone') ? hcdr_created_at_timezone() : null;

        return esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts, $tz));
    }

    protected function column_contact($item)
    {
        $first = isset($item->contact_first_name) ? (string) $item->contact_first_name : '';
        $last = isset($item->contact_last_name) ? (string) $item->contact_last_name : '';
        $full = trim($first . ' ' . $last);

        return $full !== '' ? esc_html($full) : '—';
    }

    protected function column_email($item)
    {
        $email = isset($item->email) ? (string) $item->email : '';

        return $email !== '' ? '<a href="' . esc_url('mailto:' . $email) . '">' . esc_html($email) . '</a>' : '—';
    }
}

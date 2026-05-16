<?php
/**
 * Ofast X - Email Contacts & CSV Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Email_Contacts
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ofast_email_contacts';
        $this->maybe_create_table();
    }

    private function maybe_create_table()
    {
        global $wpdb;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$this->table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(100) NOT NULL,
                first_name VARCHAR(50) DEFAULT '',
                last_name VARCHAR(50) DEFAULT '',
                status VARCHAR(20) DEFAULT 'subscribed',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY email (email)
            ) $charset;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    public function render_page()
    {
        // Handle CSV Upload
        if (isset($_POST['ofast_import_contacts']) && isset($_FILES['csv_file'])) {
            $this->handle_csv_upload();
        }
        
        // Handle Delete
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['contact_id'])) {
            $this->handle_delete();
        }

        $this->render_ui();
    }

    private function handle_csv_upload()
    {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'ofast_import_contacts')) {
            echo Ofast_X_Toast::render('Security check failed.', 'error');
            return;
        }

        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            echo Ofast_X_Toast::render('Error uploading file.', 'error');
            return;
        }

        // Basic file extension check (MIME types can be unreliable for CSV)
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            echo Ofast_X_Toast::render('Please upload a valid CSV file.', 'error');
            return;
        }

        global $wpdb;
        $handle = fopen($file['tmp_name'], 'r');
        $imported = 0;
        $skipped = 0;
        $row_num = 0;

        // Auto-detect columns
        $header = fgetcsv($handle);
        $email_idx = -1;
        $fname_idx = -1;
        $lname_idx = -1;

        if ($header) {
            foreach ($header as $i => $col) {
                $col_lower = strtolower(trim($col));
                if (in_array($col_lower, ['email', 'email address', 'e-mail'])) {
                    $email_idx = $i;
                } elseif (in_array($col_lower, ['first name', 'firstname', 'name'])) {
                    $fname_idx = $i;
                } elseif (in_array($col_lower, ['last name', 'lastname'])) {
                    $lname_idx = $i;
                }
            }
        }

        // If we couldn't find an email column, assume it's the first column
        if ($email_idx === -1) {
            $email_idx = 0;
            // Reset pointer if no header was found
            rewind($handle);
        }

        while (($data = fgetcsv($handle)) !== FALSE) {
            $row_num++;
            
            // Skip empty rows
            if (empty($data) || !isset($data[$email_idx])) continue;

            $email = sanitize_email(trim($data[$email_idx]));
            if (!is_email($email)) {
                $skipped++;
                continue;
            }

            $first_name = $fname_idx !== -1 && isset($data[$fname_idx]) ? sanitize_text_field(trim($data[$fname_idx])) : '';
            $last_name = $lname_idx !== -1 && isset($data[$lname_idx]) ? sanitize_text_field(trim($data[$lname_idx])) : '';

            // Insert or update (ignore duplicates)
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_name} WHERE email = %s", $email));
            
            if (!$existing) {
                $wpdb->insert($this->table_name, array(
                    'email' => $email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'status' => 'subscribed',
                    'created_at' => current_time('mysql')
                ));
                $imported++;
            } else {
                $skipped++;
            }
        }
        fclose($handle);

        if ($imported > 0) {
            echo Ofast_X_Toast::render("Successfully imported {$imported} contacts. Skipped {$skipped} invalid/duplicate rows.", 'success', true);
        } else {
            echo Ofast_X_Toast::render("No new contacts imported. {$skipped} rows skipped.", 'warning', true);
        }
    }

    private function handle_delete()
    {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'delete_contact_' . $_GET['contact_id'])) {
            return;
        }

        global $wpdb;
        $wpdb->delete($this->table_name, array('id' => intval($_GET['contact_id'])));
        echo Ofast_X_Toast::render('Contact deleted.', 'success', true);
    }

    private function render_ui()
    {
        global $wpdb;
        
        // Pagination logic
        $per_page = 20;
        $current_page = isset($_GET['c_paged']) ? max(1, intval($_GET['c_paged'])) : 1;
        
        // Search logic
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $where = '';
        if ($search) {
            $where = $wpdb->prepare(" WHERE email LIKE %s OR first_name LIKE %s OR last_name LIKE %s", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
        }

        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} {$where}");
        $total_pages = ceil($total_items / $per_page);
        if ($current_page > $total_pages && $total_pages > 0) {
            $current_page = $total_pages;
        }
        $offset = ($current_page - 1) * $per_page;

        $contacts = $wpdb->get_results("SELECT * FROM {$this->table_name} {$where} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}");

        ?>
        <div class="ofast-email-form-layout" style="margin-top: 20px;">
            <!-- Left Column: Data Table -->
            <div class="ofast-form-main">
                <div class="ofast-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin:0;">Imported Contacts</h2>
                        <form method="get" action="">
                            <input type="hidden" name="page" value="ofast-emailer">
                            <input type="hidden" name="tab" value="contacts">
                            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search contacts..." style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 12px; font-size: 13px;">
                            <button type="submit" class="button">Search</button>
                            <?php if ($search): ?>
                                <a href="?page=ofast-emailer&tab=contacts" class="button">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>First Name</th>
                                <th>Last Name</th>
                                <th>Status</th>
                                <th>Date Added</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($contacts)): ?>
                                <tr>
                                    <td colspan="6">No contacts found. Use the importer to add external leads.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($contacts as $c): 
                                    $del_url = wp_nonce_url(admin_url("admin.php?page=ofast-emailer&tab=contacts&action=delete&contact_id={$c->id}"), 'delete_contact_' . $c->id);
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($c->email); ?></strong></td>
                                        <td><?php echo esc_html($c->first_name); ?></td>
                                        <td><?php echo esc_html($c->last_name); ?></td>
                                        <td>
                                            <span style="padding: 2px 8px; border-radius: 4px; font-size: 11px; background: <?php echo $c->status === 'subscribed' ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $c->status === 'subscribed' ? '#166534' : '#991b1b'; ?>;">
                                                <?php echo esc_html(ucfirst($c->status)); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html(date('Y-m-d', strtotime($c->created_at))); ?></td>
                                        <td>
                                            <a href="<?php echo $del_url; ?>" style="color: #ef4444; text-decoration: none;" onclick="return confirm('Delete this contact?');">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <!-- Basic Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div style="margin-top: 15px; display: flex; justify-content: flex-end; gap: 5px;">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=ofast-emailer&tab=contacts&c_paged=<?php echo $i; ?><?php echo $search ? '&s='.urlencode($search) : ''; ?>" 
                                   style="padding: 5px 10px; border: 1px solid #e2e8f0; border-radius: 4px; text-decoration: none; <?php echo $i === $current_page ? 'background: #6366f1; color: #fff; border-color: #6366f1;' : 'background: #fff; color: #333;'; ?>">
                                   <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: CSV Importer -->
            <div class="ofast-form-sidebar">
                <div class="ofast-card" style="padding: 20px;">
                    <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 16px;">CSV Importer</h3>
                    <p style="font-size: 13px; color: #64748b; margin-bottom: 15px;">Upload a CSV file containing external email leads. Valid columns: <code>Email</code>, <code>First Name</code>, <code>Last Name</code>.</p>
                    
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('ofast_import_contacts'); ?>
                        <div style="border: 2px dashed #cbd5e1; border-radius: 8px; padding: 20px; text-align: center; margin-bottom: 15px; background: #f8fafc;">
                            <input type="file" name="csv_file" accept=".csv" required style="max-width: 100%;">
                        </div>
                        <button type="submit" name="ofast_import_contacts" class="button button-primary" style="width: 100%;">Import Contacts</button>
                    </form>
                </div>

                <div class="ofast-card" style="padding: 20px; background: #eff6ff; border-color: #bfdbfe;">
                    <h4 style="margin: 0 0 10px 0; color: #1e40af;"><span class="dashicons dashicons-info" style="color: #3b82f6;"></span> Sending Campaigns</h4>
                    <p style="font-size: 13px; color: #1e3a8a; margin: 0;">
                        To email these contacts, simply check the <strong>"Include Imported Contacts"</strong> box on the Send Email tab. 
                        Unsubscribed contacts will automatically be excluded.
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}

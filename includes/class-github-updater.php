<?php
/**
 * GitHub Plugin Updater Class
 * Handles automatic updates from GitHub repository
 */
class Schemati_GitHub_Updater {
    
    private $plugin_file;
    private $plugin_data;
    private $username;
    private $repository;
    private $access_token;
    private $plugin_activated;
    
    /**
     * Initialize the updater
     */
    public function __construct($plugin_file, $github_username, $github_repo, $access_token = '') {
        $this->plugin_file = $plugin_file;
        $this->plugin_data = get_plugin_data($plugin_file);
        $this->username = $github_username;
        $this->repository = $github_repo;
        $this->access_token = $access_token;
        $this->plugin_activated = is_plugin_active(plugin_basename($plugin_file));
        
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        
        // Add update check to admin
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_schemati_check_update', array($this, 'ajax_check_update'));
    }
    
    /**
     * Initialize GitHub updater
     */
    private function init_github_updater() {
        // Check if the updater file exists
        $updater_file = SCHEMATI_DIR . 'includes/class-github-updater.php';
        
        if (!file_exists($updater_file)) {
            // Create includes directory if it doesn't exist
            $includes_dir = SCHEMATI_DIR . 'includes/';
            if (!file_exists($includes_dir)) {
                wp_mkdir_p($includes_dir);
            }
            
            // Log error for debugging
            error_log('Schemati: GitHub updater file not found at ' . $updater_file);
            return;
        }
        
        // Include the GitHub updater class
        require_once $updater_file;
        
        // Check if class exists
        if (!class_exists('Schemati_GitHub_Updater')) {
            error_log('Schemati: GitHub updater class not found');
            return;
        }
        
        try {
            // Initialize updater with your GitHub repository details
            $this->github_updater = new Schemati_GitHub_Updater(
                SCHEMATI_FILE,           // Plugin file path
                'SchemaMarkApp',   // Replace with your GitHub username
                'schemati-2.0',              // Replace with your repository name
                ''                       // Optional: GitHub personal access token for private repos
            );
        } catch (Exception $e) {
            error_log('Schemati: Failed to initialize GitHub updater - ' . $e->getMessage());
        }
    }
    
    /**
     * Updates management page
     */
    public function updates_page() {
        if (isset($_POST['check_update_nonce']) && wp_verify_nonce($_POST['check_update_nonce'], 'schemati_check_update')) {
            if (current_user_can('update_plugins') && $this->github_updater) {
                $this->github_updater->force_update_check();
                echo '<div class="notice notice-success"><p>' . __('Update check completed!', 'schemati') . '</p></div>';
            }
        }
        
        $remote_version = ($this->github_updater) ? $this->github_updater->get_remote_version() : null;
        $current_version = SCHEMATI_VERSION;
        $update_available = $remote_version && version_compare($current_version, $remote_version, '<');
        
        ?>
        <div class="wrap">
            <h1><?php _e('Schemati - Updates', 'schemati'); ?></h1>
            
            <div class="card">
                <h2><?php _e('Version Information', 'schemati'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Current Version', 'schemati'); ?></th>
                        <td>
                            <strong><?php echo esc_html($current_version); ?></strong>
                            <?php if ($update_available): ?>
                                <span style="color: #d63384; margin-left: 10px;">
                                    <?php _e('(Update Available)', 'schemati'); ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #198754; margin-left: 10px;">
                                    <?php _e('(Up to Date)', 'schemati'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Latest Version', 'schemati'); ?></th>
                        <td>
                            <?php if ($remote_version): ?>
                                <strong><?php echo esc_html($remote_version); ?></strong>
                            <?php else: ?>
                                <em><?php _e('Unable to check', 'schemati'); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Update Source', 'schemati'); ?></th>
                        <td>
                            <a href="https://github.com/your-github-username/schemati" target="_blank">
                                GitHub Repository
                            </a>
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php if ($update_available): ?>
            <div class="card">
                <h2><?php _e('Update Available', 'schemati'); ?></h2>
                <p><?php printf(__('A new version (%s) is available for download.', 'schemati'), $remote_version); ?></p>
                
                <?php
                $plugin_slug = plugin_basename(SCHEMATI_FILE);
                $update_url = wp_nonce_url(
                    self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($plugin_slug)),
                    'upgrade-plugin_' . $plugin_slug
                );
                ?>
                
                <p>
                    <a href="<?php echo esc_url($update_url); ?>" class="button button-primary">
                        <?php _e('Update Now', 'schemati'); ?>
                    </a>
                    <a href="https://github.com/your-github-username/schemati/releases/latest" target="_blank" class="button button-secondary">
                        <?php _e('View Release Notes', 'schemati'); ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <h2><?php _e('Manual Update Check', 'schemati'); ?></h2>
                <p><?php _e('Click the button below to manually check for updates from GitHub.', 'schemati'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('schemati_check_update', 'check_update_nonce'); ?>
                    <p>
                        <button type="submit" class="button button-secondary">
                            <?php _e('Check for Updates', 'schemati'); ?>
                        </button>
                    </p>
                </form>
            </div>
            
            <div class="card">
                <h2><?php _e('Auto-Update Settings', 'schemati'); ?></h2>
                <p><?php _e('Schemati checks for updates automatically:', 'schemati'); ?></p>
                <ul>
                    <li><?php _e('Every 12 hours when accessing WordPress admin', 'schemati'); ?></li>
                    <li><?php _e('When visiting the Updates page', 'schemati'); ?></li>
                    <li><?php _e('When manually checking via the button above', 'schemati'); ?></li>
                </ul>
                
                <h3><?php _e('Release Types', 'schemati'); ?></h3>
                <p><?php _e('Schemati follows semantic versioning:', 'schemati'); ?></p>
                <ul>
                    <li><strong><?php _e('Major updates (x.0.0)', 'schemati'); ?></strong> - <?php _e('New features, breaking changes', 'schemati'); ?></li>
                    <li><strong><?php _e('Minor updates (x.y.0)', 'schemati'); ?></strong> - <?php _e('New features, improvements', 'schemati'); ?></li>
                    <li><strong><?php _e('Patch updates (x.y.z)', 'schemati'); ?></strong> - <?php _e('Bug fixes, security updates', 'schemati'); ?></li>
                </ul>
            </div>
            
            <div class="card">
                <h2><?php _e('Troubleshooting', 'schemati'); ?></h2>
                <h4><?php _e('Update Not Showing?', 'schemati'); ?></h4>
                <ul>
                    <li><?php _e('Check your internet connection', 'schemati'); ?></li>
                    <li><?php _e('Try the manual update check above', 'schemati'); ?></li>
                    <li><?php _e('Clear any caching plugins', 'schemati'); ?></li>
                    <li><?php _e('Contact support if the issue persists', 'schemati'); ?></li>
                </ul>
                
                <h4><?php _e('Update Failed?', 'schemati'); ?></h4>
                <ul>
                    <li><?php _e('Check file permissions on the plugins directory', 'schemati'); ?></li>
                    <li><?php _e('Ensure you have sufficient disk space', 'schemati'); ?></li>
                    <li><?php _e('Try updating via FTP if automatic update fails', 'schemati'); ?></li>
                    <li><?php _e('Backup your site before major updates', 'schemati'); ?></li>
                </ul>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Auto-refresh update status every 30 seconds
            setInterval(function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'schemati_check_update',
                        nonce: '<?php echo wp_create_nonce('schemati_update_check'); ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data.update_available) {
                            $('.wrap').prepend('<div class="notice notice-warning"><p><strong>New version available:</strong> ' + response.data.version + '</p></div>');
                        }
                    }
                });
            }, 30000);
        });
        </script>
        <?php
    
    /**
     * Add admin hooks
     */
    public function admin_init() {
        add_action('admin_notices', array($this, 'admin_notices'));
    }
    
    /**
     * Check for updates and modify the update transient
     */
    public function modify_transient($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get remote version
        $remote_version = $this->get_remote_version();
        
        if (!$remote_version) {
            return $transient;
        }
        
        // Compare versions
        if (version_compare($this->plugin_data['Version'], $remote_version, '<')) {
            $plugin_slug = plugin_basename($this->plugin_file);
            
            $transient->response[$plugin_slug] = (object) array(
                'slug' => dirname($plugin_slug),
                'plugin' => $plugin_slug,
                'new_version' => $remote_version,
                'url' => $this->plugin_data['PluginURI'],
                'package' => $this->get_download_url($remote_version),
                'tested' => $this->get_tested_wp_version(),
                'requires_php' => $this->plugin_data['RequiresPHP'] ?? '7.4',
                'compatibility' => new stdClass(),
            );
        }
        
        return $transient;
    }
    
    /**
     * Get remote version from GitHub
     */
    private function get_remote_version() {
        $transient_key = 'schemati_remote_version';
        $remote_version = get_transient($transient_key);
        
        if (false === $remote_version) {
            $api_url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
            
            $args = array(
                'timeout' => 30,
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress Plugin Updater'
                )
            );
            
            if (!empty($this->access_token)) {
                $args['headers']['Authorization'] = 'token ' . $this->access_token;
            }
            
            $response = wp_remote_get($api_url, $args);
            
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return false;
            }
            
            $body = wp_remote_retrieve_body($response);
            $release_data = json_decode($body, true);
            
            if (empty($release_data['tag_name'])) {
                return false;
            }
            
            $remote_version = ltrim($release_data['tag_name'], 'v');
            
            // Cache for 6 hours
            set_transient($transient_key, $remote_version, 6 * HOUR_IN_SECONDS);
        }
        
        return $remote_version;
    }
    
    /**
     * Get download URL for the latest release
     */
    private function get_download_url($version = '') {
        if (empty($version)) {
            $version = $this->get_remote_version();
        }
        
        // Try to get the zipball URL from the release
        $api_url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
        
        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress Plugin Updater'
            )
        );
        
        if (!empty($this->access_token)) {
            $args['headers']['Authorization'] = 'token ' . $this->access_token;
        }
        
        $response = wp_remote_get($api_url, $args);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $release_data = json_decode($body, true);
            
            if (!empty($release_data['zipball_url'])) {
                return $release_data['zipball_url'];
            }
        }
        
        // Fallback to archive URL
        return "https://github.com/{$this->username}/{$this->repository}/archive/refs/tags/v{$version}.zip";
    }
    
    /**
     * Get tested WordPress version
     */
    private function get_tested_wp_version() {
        return $this->plugin_data['Tested up to'] ?? get_bloginfo('version');
    }
    
    /**
     * Show plugin information popup
     */
    public function plugin_popup($response, $action, $args) {
        if ($action !== 'plugin_information') {
            return $response;
        }
        
        if (empty($args->slug) || $args->slug !== dirname(plugin_basename($this->plugin_file))) {
            return $response;
        }
        
        $remote_version = $this->get_remote_version();
        $changelog = $this->get_changelog();
        
        $response = (object) array(
            'slug' => $args->slug,
            'plugin_name' => $this->plugin_data['Name'],
            'name' => $this->plugin_data['Name'],
            'version' => $remote_version,
            'author' => $this->plugin_data['AuthorName'],
            'author_profile' => $this->plugin_data['AuthorURI'],
            'last_updated' => $this->get_last_updated(),
            'homepage' => $this->plugin_data['PluginURI'],
            'short_description' => $this->plugin_data['Description'],
            'sections' => array(
                'Description' => $this->plugin_data['Description'],
                'Updates' => $changelog,
                'Installation' => 'Upload the plugin files to WordPress and activate.',
            ),
            'download_link' => $this->get_download_url($remote_version),
            'tested' => $this->get_tested_wp_version(),
            'requires_php' => $this->plugin_data['RequiresPHP'] ?? '7.4',
        );
        
        return $response;
    }
    
    /**
     * Get changelog from GitHub releases
     */
    private function get_changelog() {
        $transient_key = 'schemati_changelog';
        $changelog = get_transient($transient_key);
        
        if (false === $changelog) {
            $api_url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases";
            
            $args = array(
                'timeout' => 30,
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress Plugin Updater'
                )
            );
            
            if (!empty($this->access_token)) {
                $args['headers']['Authorization'] = 'token ' . $this->access_token;
            }
            
            $response = wp_remote_get($api_url, $args);
            
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return '<p>Unable to fetch changelog.</p>';
            }
            
            $body = wp_remote_retrieve_body($response);
            $releases = json_decode($body, true);
            
            $changelog = '<div class="schemati-changelog">';
            
            foreach (array_slice($releases, 0, 5) as $release) {
                $version = ltrim($release['tag_name'], 'v');
                $date = date('Y-m-d', strtotime($release['published_at']));
                $notes = !empty($release['body']) ? wp_kses_post($release['body']) : 'No release notes available.';
                
                $changelog .= '<div class="changelog-release">';
                $changelog .= '<h4>Version ' . esc_html($version) . ' - ' . esc_html($date) . '</h4>';
                $changelog .= '<div class="changelog-notes">' . $notes . '</div>';
                $changelog .= '</div>';
            }
            
            $changelog .= '</div>';
            
            // Cache for 2 hours
            set_transient($transient_key, $changelog, 2 * HOUR_IN_SECONDS);
        }
        
        return $changelog;
    }
    
    /**
     * Get last updated date
     */
    private function get_last_updated() {
        $api_url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
        
        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress Plugin Updater'
            )
        );
        
        if (!empty($this->access_token)) {
            $args['headers']['Authorization'] = 'token ' . $this->access_token;
        }
        
        $response = wp_remote_get($api_url, $args);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $release_data = json_decode($body, true);
            
            if (!empty($release_data['published_at'])) {
                return $release_data['published_at'];
            }
        }
        
        return date('Y-m-d H:i:s');
    }
    
    /**
     * Perform additional actions after plugin installation
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        $install_directory = plugin_dir_path($this->plugin_file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        
        if ($this->plugin_activated) {
            activate_plugin(plugin_basename($this->plugin_file));
        }
        
        return $result;
    }
    
    /**
     * Admin notices for updates
     */
    public function admin_notices() {
        if (!current_user_can('update_plugins')) {
            return;
        }
        
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'schemati') === false) {
            return;
        }
        
        $remote_version = $this->get_remote_version();
        
        if ($remote_version && version_compare($this->plugin_data['Version'], $remote_version, '<')) {
            $plugin_slug = plugin_basename($this->plugin_file);
            $update_url = wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($plugin_slug)), 'upgrade-plugin_' . $plugin_slug);
            
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . esc_html($this->plugin_data['Name']) . '</strong></p>';
            echo '<p>A new version (' . esc_html($remote_version) . ') is available. You are running version ' . esc_html($this->plugin_data['Version']) . '.</p>';
            echo '<p>';
            echo '<a href="' . esc_url($update_url) . '" class="button button-primary">Update Now</a> ';
            echo '<button type="button" class="button" onclick="schematiCheckUpdate()">Check for Update</button>';
            echo '</p>';
            echo '</div>';
            
            // Add JavaScript for manual update check
            ?>
            <script>
            function schematiCheckUpdate() {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'schemati_check_update',
                        nonce: '<?php echo wp_create_nonce('schemati_update_check'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Update check completed. Latest version: ' + response.data.version);
                            location.reload();
                        } else {
                            alert('Update check failed: ' + response.data);
                        }
                    }
                });
            }
            </script>
            <?php
        }
    }
    
    /**
     * AJAX handler for manual update check
     */
    public function ajax_check_update() {
        check_ajax_referer('schemati_update_check', 'nonce');
        
        if (!current_user_can('update_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Clear cached version
        delete_transient('schemati_remote_version');
        delete_transient('schemati_changelog');
        
        $remote_version = $this->get_remote_version();
        
        if ($remote_version) {
            wp_send_json_success(array(
                'version' => $remote_version,
                'current' => $this->plugin_data['Version'],
                'update_available' => version_compare($this->plugin_data['Version'], $remote_version, '<')
            ));
        } else {
            wp_send_json_error('Unable to check for updates');
        }
    }
    
    /**
     * Force check for updates (can be called manually)
     */
    public function force_update_check() {
        delete_transient('schemati_remote_version');
        delete_transient('schemati_changelog');
        delete_site_transient('update_plugins');
        
        return $this->get_remote_version();
    }
}
<?php
/**
 * Enhanced GitHub Plugin Updater Class
 * Handles automatic updates from GitHub repository with advanced features
 */
class Schemati_GitHub_Updater {
    
    private $plugin_file;
    private $plugin_data;
    private $username;
    private $repository;
    private $access_token;
    private $plugin_activated;
    private $update_server_url;
    
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
        $this->update_server_url = "https://api.github.com/repos/{$this->username}/{$this->repository}";
        
        // Core update hooks
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        
        // Enhanced admin integration
        add_action('admin_init', array($this, 'updater_admin_init'));
        add_action('wp_ajax_schemati_updater_check', array($this, 'ajax_check_update'));
        add_action('wp_ajax_schemati_updater_clear_cache', array($this, 'ajax_clear_cache'));
        
        // Background update checks
        add_action('wp_loaded', array($this, 'maybe_check_for_updates'));
    }
    
    /**
     * Initialize updater-specific admin functionality
     */
    public function updater_admin_init() {
        // Only run on admin pages
        if (!is_admin()) {
            return;
        }
        
        // Add update notices
        add_action('admin_notices', array($this, 'show_update_notices'));
        
        // Check for updates every 12 hours
        $last_check = get_transient('schemati_last_update_check');
        if (!$last_check || (time() - $last_check) > (12 * HOUR_IN_SECONDS)) {
            $this->background_update_check();
        }
    }
    
    /**
     * Background update check (non-blocking)
     */
    private function background_update_check() {
        if (!wp_next_scheduled('schemati_background_update_check')) {
            wp_schedule_single_event(time() + 60, 'schemati_background_update_check');
            add_action('schemati_background_update_check', array($this, 'force_update_check'));
        }
        set_transient('schemati_last_update_check', time(), 12 * HOUR_IN_SECONDS);
    }
    
    /**
     * Maybe check for updates on page load
     */
    public function maybe_check_for_updates() {
        // Only check on specific admin pages
        if (!is_admin()) {
            return;
        }
        
        $screen = get_current_screen();
        if ($screen && (
            strpos($screen->id, 'update') !== false || 
            strpos($screen->id, 'schemati') !== false
        )) {
            // Light check without API call
            $this->check_cached_version();
        }
    }
    
    /**
     * Check cached version info
     */
    private function check_cached_version() {
        $remote_version = get_transient('schemati_remote_version');
        if ($remote_version && version_compare($this->plugin_data['Version'], $remote_version, '<')) {
            // Update available - set flag for admin notice
            set_transient('schemati_update_available', $remote_version, DAY_IN_SECONDS);
        }
    }
    
    /**
     * Enhanced admin notices
     */
    public function show_update_notices() {
        if (!current_user_can('update_plugins')) {
            return;
        }
        
        $update_version = get_transient('schemati_update_available');
        if (!$update_version) {
            return;
        }
        
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'schemati') !== false) {
            $this->render_update_notice($update_version);
        }
    }
    
    /**
     * Render enhanced update notice
     */
    private function render_update_notice($new_version) {
        $plugin_slug = plugin_basename($this->plugin_file);
        $update_url = wp_nonce_url(
            self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($plugin_slug)), 
            'upgrade-plugin_' . $plugin_slug
        );
        
        ?>
        <div class="notice notice-warning is-dismissible schemati-update-notice" style="border-left-color: #0073aa;">
            <div style="display: flex; align-items: center; padding: 5px 0;">
                <div style="margin-right: 15px; font-size: 24px;">🚀</div>
                <div style="flex: 1;">
                    <h3 style="margin: 0; color: #0073aa;">Schemati Update Available</h3>
                    <p style="margin: 5px 0 10px 0;">
                        Version <strong><?php echo esc_html($new_version); ?></strong> is now available. 
                        You are running version <strong><?php echo esc_html($this->plugin_data['Version']); ?></strong>.
                    </p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="<?php echo esc_url($update_url); ?>" class="button button-primary">
                            ⬆️ Update Now
                        </a>
                        <a href="https://github.com/<?php echo esc_attr($this->username); ?>/<?php echo esc_attr($this->repository); ?>/releases/latest" target="_blank" class="button button-secondary">
                            📋 View Release Notes
                        </a>
                        <button type="button" class="button" onclick="schematiCheckUpdate()">
                            🔄 Check Again
                        </button>
                        <button type="button" class="button" onclick="schematiDismissUpdate()">
                            ❌ Dismiss
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        function schematiCheckUpdate() {
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'schemati_updater_check',
                    nonce: '<?php echo wp_create_nonce('schemati_updater_check'); ?>'
                },
                beforeSend: function() {
                    jQuery('button').prop('disabled', true).text('Checking...');
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.update_available) {
                            alert('Update confirmed! Version ' + response.data.version + ' is available.');
                        } else {
                            alert('You are running the latest version.');
                            jQuery('.schemati-update-notice').fadeOut();
                        }
                    } else {
                        alert('Update check failed: ' + response.data);
                    }
                },
                complete: function() {
                    jQuery('button').prop('disabled', false).text('Check Again');
                }
            });
        }
        
        function schematiDismissUpdate() {
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'schemati_updater_clear_cache',
                    nonce: '<?php echo wp_create_nonce('schemati_updater_clear_cache'); ?>'
                },
                success: function() {
                    jQuery('.schemati-update-notice').fadeOut();
                }
            });
        }
        </script>
        <?php
    }
    
    /**
     * Enhanced update transient modification
     */
    public function modify_transient($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get remote version with enhanced error handling
        $remote_version = $this->get_remote_version();
        
        if (!$remote_version) {
            // Log the failure for debugging
            $this->log_update_error('Failed to retrieve remote version');
            return $transient;
        }
        
        // Compare versions with detailed logging
        if (version_compare($this->plugin_data['Version'], $remote_version, '<')) {
            $plugin_slug = plugin_basename($this->plugin_file);
            
            $update_data = (object) array(
                'slug' => dirname($plugin_slug),
                'plugin' => $plugin_slug,
                'new_version' => $remote_version,
                'url' => $this->plugin_data['PluginURI'],
                'package' => $this->get_download_url($remote_version),
                'tested' => $this->get_tested_wp_version(),
                'requires_php' => $this->plugin_data['RequiresPHP'] ?? '7.4',
                'compatibility' => new stdClass(),
                'upgrade_notice' => $this->get_upgrade_notice($remote_version),
                'banners' => array(
                    'low' => 'https://raw.githubusercontent.com/' . $this->username . '/' . $this->repository . '/main/assets/banner-772x250.png',
                    'high' => 'https://raw.githubusercontent.com/' . $this->username . '/' . $this->repository . '/main/assets/banner-1544x500.png'
                ),
                'icons' => array(
                    '1x' => 'https://raw.githubusercontent.com/' . $this->username . '/' . $this->repository . '/main/assets/icon-128x128.png',
                    '2x' => 'https://raw.githubusercontent.com/' . $this->username . '/' . $this->repository . '/main/assets/icon-256x256.png'
                )
            );
            
            $transient->response[$plugin_slug] = $update_data;
            
            // Set update available flag
            set_transient('schemati_update_available', $remote_version, DAY_IN_SECONDS);
            
            $this->log_update_info("Update available: {$this->plugin_data['Version']} -> {$remote_version}");
        } else {
            // Remove update available flag if we're up to date
            delete_transient('schemati_update_available');
        }
        
        return $transient;
    }
    
    /**
     * Get upgrade notice for specific version
     */
    private function get_upgrade_notice($version) {
        $notices = array(
            '5.2.0' => 'Major update with new schema types and enhanced UI. Backup recommended.',
            '5.1.1' => 'Bug fixes and performance improvements.',
            // Add version-specific notices here
        );
        
        return $notices[$version] ?? 'Update includes improvements and bug fixes.';
    }
    
    /**
     * Enhanced remote version checking with retry logic
     */
    public function get_remote_version() {
        $transient_key = 'schemati_remote_version';
        $remote_version = get_transient($transient_key);
        
        if (false === $remote_version) {
            $remote_version = $this->fetch_remote_version_with_retry();
            
            if ($remote_version) {
                // Cache for 6 hours on success
                set_transient($transient_key, $remote_version, 6 * HOUR_IN_SECONDS);
            } else {
                // Cache failure for 1 hour to avoid repeated API calls
                set_transient($transient_key . '_failed', true, HOUR_IN_SECONDS);
            }
        }
        
        return $remote_version;
    }
    
    /**
     * Fetch remote version with retry logic
     */
    private function fetch_remote_version_with_retry($max_retries = 3) {
        for ($i = 0; $i < $max_retries; $i++) {
            $response = $this->make_github_api_request('/releases/latest');
            
            if ($response && !is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $release_data = json_decode($body, true);
                
                if (!empty($release_data['tag_name'])) {
                    $version = ltrim($release_data['tag_name'], 'v');
                    $this->log_update_info("Successfully fetched version: {$version}");
                    return $version;
                }
            }
            
            // Wait before retry (exponential backoff)
            if ($i < $max_retries - 1) {
                sleep(pow(2, $i));
            }
        }
        
        $this->log_update_error("Failed to fetch remote version after {$max_retries} attempts");
        return false;
    }
    
    /**
     * Make standardized GitHub API request
     */
    private function make_github_api_request($endpoint, $timeout = 30) {
        $api_url = $this->update_server_url . $endpoint;
        
        $args = array(
            'timeout' => $timeout,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress Plugin Updater - ' . $this->plugin_data['Name']
            )
        );
        
        if (!empty($this->access_token)) {
            $args['headers']['Authorization'] = 'token ' . $this->access_token;
        }
        
        return wp_remote_get($api_url, $args);
    }
    
    /**
     * Enhanced download URL with asset support
     */
    public function get_download_url($version = '') {
        if (empty($version)) {
            $version = $this->get_remote_version();
        }
        
        // Try to get specific release asset first
        $response = $this->make_github_api_request('/releases/latest');
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $release_data = json_decode($body, true);
            
            // Look for plugin-specific asset
            if (!empty($release_data['assets'])) {
                foreach ($release_data['assets'] as $asset) {
                    if (strpos($asset['name'], 'schemati') !== false && 
                        (strpos($asset['name'], '.zip') !== false || strpos($asset['name'], '.tar.gz') !== false)) {
                        return $asset['browser_download_url'];
                    }
                }
            }
            
            // Use zipball_url as fallback
            if (!empty($release_data['zipball_url'])) {
                return $release_data['zipball_url'];
            }
        }
        
        // Final fallback to archive URL
        return "https://github.com/{$this->username}/{$this->repository}/archive/refs/tags/v{$version}.zip";
    }
    
    /**
     * Enhanced plugin popup with better formatting
     */
    public function plugin_popup($response, $action, $args) {
        if ($action !== 'plugin_information') {
            return $response;
        }
        
        if (empty($args->slug) || $args->slug !== dirname(plugin_basename($this->plugin_file))) {
            return $response;
        }
        
        $remote_version = $this->get_remote_version();
        $changelog = $this->get_enhanced_changelog();
        
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
                'Description' => $this->get_enhanced_description(),
                'Installation' => $this->get_installation_instructions(),
                'Changelog' => $changelog,
                'FAQ' => $this->get_faq_section(),
                'Support' => $this->get_support_section(),
            ),
            'download_link' => $this->get_download_url($remote_version),
            'tested' => $this->get_tested_wp_version(),
            'requires_php' => $this->plugin_data['RequiresPHP'] ?? '7.4',
            'requires' => '5.0',
            'rating' => 95,
            'num_ratings' => 1,
            'downloaded' => 1000,
            'active_installs' => 500,
        );
        
        return $response;
    }
    
    /**
     * Get enhanced description
     */
    private function get_enhanced_description() {
        return $this->plugin_data['Description'] . 
               '<h3>Key Features:</h3>' .
               '<ul>' .
               '<li>Complete Schema.org markup support</li>' .
               '<li>Visual schema editor</li>' .
               '<li>Real-time validation</li>' .
               '<li>Breadcrumb support</li>' .
               '<li>Multiple schema types</li>' .
               '</ul>';
    }
    
    /**
     * Get installation instructions
     */
    private function get_installation_instructions() {
        return '<ol>' .
               '<li>Upload the plugin files to your WordPress installation</li>' .
               '<li>Activate the plugin through the WordPress admin</li>' .
               '<li>Configure schema settings via the Schemati menu</li>' .
               '<li>Use the sidebar editor for custom schemas</li>' .
               '</ol>';
    }
    
    /**
     * Get FAQ section
     */
    private function get_faq_section() {
        return '<h4>How do I validate my schema?</h4>' .
               '<p>Use the built-in validation tool or visit Google\'s Rich Results Test.</p>' .
               '<h4>Can I add custom schema types?</h4>' .
               '<p>Yes, use the visual editor to create custom schemas for any page.</p>';
    }
    
    /**
     * Get support section
     */
    private function get_support_section() {
        return '<p>For support, please visit our <a href="https://github.com/' . $this->username . '/' . $this->repository . '/issues" target="_blank">GitHub Issues</a> page.</p>';
    }
    
    /**
     * Enhanced changelog with better formatting
     */
    private function get_enhanced_changelog() {
        $transient_key = 'schemati_enhanced_changelog';
        $changelog = get_transient($transient_key);
        
        if (false === $changelog) {
            $response = $this->make_github_api_request('/releases');
            
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return '<p>Unable to fetch changelog.</p>';
            }
            
            $body = wp_remote_retrieve_body($response);
            $releases = json_decode($body, true);
            
            $changelog = '<div class="schemati-changelog">';
            
            foreach (array_slice($releases, 0, 10) as $release) {
                $version = ltrim($release['tag_name'], 'v');
                $date = date('F j, Y', strtotime($release['published_at']));
                $notes = !empty($release['body']) ? $this->format_release_notes($release['body']) : '<p>No release notes available.</p>';
                
                $is_prerelease = $release['prerelease'] ? ' <span style="color: #d63384; font-size: 0.8em;">(Pre-release)</span>' : '';
                
                $changelog .= '<div class="changelog-release" style="margin-bottom: 25px; border-bottom: 1px solid #eee; padding-bottom: 15px;">';
                $changelog .= '<h3 style="color: #0073aa; margin-bottom: 5px;">Version ' . esc_html($version) . $is_prerelease . '</h3>';
                $changelog .= '<p style="color: #666; font-size: 0.9em; margin-bottom: 10px;">Released on ' . esc_html($date) . '</p>';
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
     * Format release notes for better display
     */
    private function format_release_notes($notes) {
        // Convert markdown-style formatting
        $notes = wp_kses_post($notes);
        $notes = preg_replace('/^### (.*)/m', '<h4>$1</h4>', $notes);
        $notes = preg_replace('/^## (.*)/m', '<h3>$1</h3>', $notes);
        $notes = preg_replace('/^\* (.*)/m', '<li>$1</li>', $notes);
        $notes = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $notes);
        
        return $notes;
    }
    
    /**
     * AJAX handler for manual update check
     */
    public function ajax_check_update() {
        check_ajax_referer('schemati_updater_check', 'nonce');
        
        if (!current_user_can('update_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Clear cached versions
        delete_transient('schemati_remote_version');
        delete_transient('schemati_enhanced_changelog');
        delete_transient('schemati_update_available');
        
        $remote_version = $this->get_remote_version();
        
        if ($remote_version) {
            $update_available = version_compare($this->plugin_data['Version'], $remote_version, '<');
            
            wp_send_json_success(array(
                'version' => $remote_version,
                'current' => $this->plugin_data['Version'],
                'update_available' => $update_available,
                'message' => $update_available ? 
                    "Update available: {$remote_version}" : 
                    'You are running the latest version.'
            ));
        } else {
            wp_send_json_error('Unable to check for updates. Please try again later.');
        }
    }
    
    /**
     * AJAX handler for clearing cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('schemati_updater_clear_cache', 'nonce');
        
        if (!current_user_can('update_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Clear all update-related transients
        delete_transient('schemati_remote_version');
        delete_transient('schemati_enhanced_changelog');
        delete_transient('schemati_update_available');
        delete_transient('schemati_last_update_check');
        delete_site_transient('update_plugins');
        
        wp_send_json_success('Cache cleared successfully');
    }
    
    /**
     * Enhanced after install with better error handling
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        $install_directory = plugin_dir_path($this->plugin_file);
        
        try {
            if (isset($result['destination'])) {
                $wp_filesystem->move($result['destination'], $install_directory);
                $result['destination'] = $install_directory;
            }
            
            if ($this->plugin_activated) {
                activate_plugin(plugin_basename($this->plugin_file));
            }
            
            // Clear update caches after successful install
            $this->clear_all_caches();
            
            $this->log_update_info('Plugin updated successfully');
        } catch (Exception $e) {
            $this->log_update_error('Installation failed: ' . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Force update check with enhanced logging
     */
    public function force_update_check() {
        $this->log_update_info('Force update check initiated');
        
        $this->clear_all_caches();
        $version = $this->get_remote_version();
        
        if ($version) {
            $this->log_update_info("Force check completed. Latest version: {$version}");
        } else {
            $this->log_update_error('Force update check failed');
        }
        
        return $version;
    }
    
    /**
     * Clear all update-related caches
     */
    private function clear_all_caches() {
        delete_transient('schemati_remote_version');
        delete_transient('schemati_enhanced_changelog');
        delete_transient('schemati_update_available');
        delete_transient('schemati_last_update_check');
        delete_site_transient('update_plugins');
    }
    
    /**
     * Get tested WordPress version
     */
    private function get_tested_wp_version() {
        return $this->plugin_data['Tested up to'] ?? get_bloginfo('version');
    }
    
    /**
     * Get last updated date
     */
    private function get_last_updated() {
        $response = $this->make_github_api_request('/releases/latest');
        
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
     * Enhanced logging for updates
     */
    private function log_update_info($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Schemati Updater [INFO]: {$message}");
        }
    }
    
    /**
     * Enhanced error logging
     */
    private function log_update_error($message) {
        error_log("Schemati Updater [ERROR]: {$message}");
        
        // Store error for admin display
        $errors = get_transient('schemati_update_errors') ?: array();
        $errors[] = array(
            'message' => $message,
            'time' => current_time('mysql')
        );
        set_transient('schemati_update_errors', array_slice($errors, -5), DAY_IN_SECONDS);
    }
    
    /**
     * Get update status for admin display
     */
    public function get_update_status() {
        return array(
            'current_version' => $this->plugin_data['Version'],
            'remote_version' => $this->get_remote_version(),
            'last_check' => get_transient('schemati_last_update_check'),
            'update_available' => get_transient('schemati_update_available'),
            'errors' => get_transient('schemati_update_errors') ?: array()
        );
    }
}
?>

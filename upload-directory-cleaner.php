<?php
/**
 * Plugin Name:         Upload Directory Cleaner
 * Description:         The UDC plugin allows you to scan and delete all unregistered files from your Wordpress upload directory.
 * Version:             0.1
 * Requires at least:   2.9
 * Requires PHP:        5.4
 * Author:              Elias Lackner
 * Author URI:          elias-lackner.at
 * License:             GPL v2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 */

class UDC {
    // Constants & Variables
    const ACTION_SAVE_SETTINGS = 'save_settings';
    const ACTION_ARCHIVE_DIRECTORY = 'archive_directory';
    const ACTION_SCAN_DIRECTORY = 'scan_directory';
    const ACTION_DELETE_FILES = 'delete_files';
    
    const SCAN_RESULT_REGISTERED_FILE = 'SCAN_RESULT_REGISTERED_FILE';
    const SCAN_RESULT_UNREGISTERED_FILE = 'SCAN_RESULT_UNREGISTERED_FILE';

    private $settings = [
        'udc_keep_thumbnails' => [
            'title' => 'Keep Thumbnails',
            'description' => 'get_keep_thumbnails_description',
            'type' => 'checkbox',
            'default_value' => true,
        ],
        'udc_delete_empty_directories' => [
            'title' => 'Delete Empty Directories',
            'type' => 'checkbox',
            'default_value' => true,
        ],
        'udc_ignore' => [
            'title' => 'Ignore',
            'type' => 'textarea',
            'default_value' => '',
            'placeholder' => '/directory/,.pdf',
        ],
    ];

    private $user_inputs = [
        'action' => [
            'sanitize' => ['sanitize_text_field'],
        ],
        'excludes' => [
            'is_array' => true,
            'sanitize' => ['sanitize_file_name'],
        ],
        'preg_excludes' => [
            'sanitize' => ['sanitize_text_field'],
        ],
        'unregistered_log' => [
            'sanitize' => ['sanitize_file_name'],
        ],
        'deletes' => [
            'is_array' => true,
            'sanitize' => ['filter_var', FILTER_SANITIZE_NUMBER_INT],
        ],
        'udc_keep_thumbnails' => [
            'sanitize' => ['rest_sanitize_boolean'],
        ],
        'udc_delete_empty_directories' => [
            'sanitize' => ['rest_sanitize_boolean'],
        ],
        'udc_ignore' => [
            'sanitize' => ['sanitize_text_field'],
        ],
    ];

    private $plugin_dir_path;
    private $plugin_dir_url;

    private $user_input;
    private $public_upload_dir;
    private $system_upload_dir;
    private $scan_excludes;
    private $direct_iterator_items;
    private $recursive_iterator_items;
    private $file_count = 0;

    private $registered_file_paths = [];
    private $public_scan_log_file_path;
    private $unregistered_log_file_path;
    private $unregistered_files = [];
    private $system_max_input_vars;

    private $public_delete_log_file_path;

    // Getters
    private function get_user_input()
    {
        $sanitized_user_input = [];

        foreach($this->user_inputs as $user_input_key => $user_input) {
            $is_array = $user_input['is_array'];
            $sanitize = $user_input['sanitize'];
            $input_data = $_POST[$user_input_key];

            if($is_array) {
                $sanitized_user_input[$user_input_key] = [];

                foreach ($input_data as $data_key => $data) {
                    $sanitized_user_input[$user_input_key][$data_key] = call_user_func($sanitize[0], $data, $sanitize[1]);
                }
            } else {
                $sanitized_user_input[$user_input_key] = call_user_func($sanitize[0], $input_data, $sanitize[1]);
            }
        }

        return $sanitized_user_input;
    }

    private function get_keep_thumbnails_description()
    {
        return 'Registered Thumbnail Sizes: ' . implode(', ', $this->get_image_sizes());
    }

    private function get_unregistered_files_size()
    {
        $unregistered_files_size = 0;

        foreach($this->unregistered_files as $file) {
            $unregistered_files_size += $file->getSize();
        }

        return $unregistered_files_size;
    }

    private function get_action()
    {
        return $this->user_input['action'];
    }

    private function get_preg_excludes()
    {
        if($this->user_input['preg_excludes']) {
            $excludes = $this->user_input['preg_excludes'];
            $excludes = str_replace('\\\\', '\\', $excludes);
        } else {
            $excludes = $this->settings['udc_ignore']['value'];
            $excludes = $excludes != '' ? explode(',', $excludes) : [];
            $excludes = array_merge($excludes, $this->scan_excludes);
            $excludes = array_map(function($exclude) {
                return preg_quote($exclude, '/');
            }, $excludes);
            $excludes = implode('|', $excludes);
        }

        return $excludes;
    }

    private function get_image_sizes()
    {
        global $_wp_additional_image_sizes;
        $image_sizes = [];
        
        foreach($_wp_additional_image_sizes as $image_size) {
            $image_sizes[] = $image_size['width'] . 'x' . $image_size['height'];
        }

        return $image_sizes;
    }

    private function get_recursive_iterator_items()
    {
        return new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->system_upload_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                function($item, $key, $iterator) {
                    if($this->get_action() == self::ACTION_ARCHIVE_DIRECTORY) {
                        return true;
                    }

                    return preg_match("/(" . $this->get_preg_excludes() . ")/i", $key) === 0;
                }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
    }

    // Constructor
    private function __construct()
    {
        $this->plugin_dir_path = plugin_dir_path(__FILE__);
        $this->plugin_dir_url = plugin_dir_url(__FILE__);

        add_action('admin_enqueue_scripts', [$this, 'include_styles_and_scripts']);
        add_action('admin_menu', [$this, 'add_page_to_tools_submenu']);
    }

    // Singleton
    static $instance = false;

    public static function getInstance()
    {
        if(!self::$instance)
            self::$instance = new self;
        return self::$instance;
    }

    // Installation
    static function install()
    {
        mkdir(__DIR__ . '/logs');
        mkdir(__DIR__ . '/logs/scan');
        mkdir(__DIR__ . '/logs/unregistered');
        mkdir(__DIR__ . '/logs/delete');
    }

    // Add Page To Tools Submenu
    public function add_page_to_tools_submenu()
    {
        add_management_page(
            'Upload Directory Cleaner',
            'Upload Directory Cleaner',
            'manage_options',
            'udc',
            [$this, 'init']
        );
    }

    // Initialization
    public function init()
    {
        $this->set_settings();

        $this->user_input = $this->get_user_input();
        $this->public_upload_dir = str_replace(site_url(), '', wp_upload_dir()['baseurl']);
        $this->system_upload_dir = wp_upload_dir()['basedir'];
        $this->scan_excludes = array_merge($this->user_input['excludes'], [$this->system_upload_dir . '/sites']);
        $this->direct_iterator_items = new DirectoryIterator($this->system_upload_dir);
        $this->recursive_iterator_items = $this->get_recursive_iterator_items();
        
        foreach($this->recursive_iterator_items as $item) {
            if($item->isFile())
                $this->file_count++;
        }

        switch($this->get_action()) {
            case self::ACTION_SAVE_SETTINGS:
                $this->save_settings();
                break;
            case self::ACTION_SCAN_DIRECTORY:
                $this->scan_directory();
                break;
            case self::ACTION_DELETE_FILES:
                $this->delete_files();
                break;
            case self::ACTION_ARCHIVE_DIRECTORY:
                $this->archive_directory();
                break;
            default: break;
        }

        $this->render_page();
    }

    // Set Settings
    private function set_settings()
    {
        foreach($this->settings as $setting_name => $setting) {
            add_option($setting_name, $setting['default_value']);
            $setting['value'] = get_option($setting_name, $setting['default_value']);
            $this->settings[$setting_name] = $setting;
        }
    }

    // Include Styles and Scripts
    public function include_styles_and_scripts($hook)
    {
        if($hook != 'tools_page_udc')
            return;
        
        wp_register_style('udc_admin_style', $this->plugin_dir_url . 'styles/admin.css');
        wp_enqueue_style('udc_admin_style');
    }

    private function delete_empty_directories_r($path, $log_file) {
        $empty = true;
        
        foreach(glob($path . DIRECTORY_SEPARATOR . '*') as $file) {
            if(preg_match("/(" . $this->get_preg_excludes() . ")/i", $file) === 1) {
                $empty = false;
            } else {
                $empty &= is_dir($file) && $this->delete_empty_directories_r($file, $log_file);
            }
        }

        if($empty) {
            fwrite($log_file, 'Deleting Empty Directory: ' . $path . PHP_EOL);
        }

        return $empty && rmdir($path);
    }

    // Actions

    // -- Save Settings
    private function save_settings()
    {
        foreach($this->settings as $setting_name => $setting) {
            $input = $this->user_input[$setting_name];
            
            update_option($setting_name, $input);
        }

        $this->set_settings();
    }

    // -- Scan Directory
    private function scan_directory()
    {
        $attachments = get_posts([
            'post_type' => 'attachment',
            'numberposts' => -1,
            'post_status' => null,
            'post_parent' => null,
        ]);
        $keep_thumbnails = (bool)$this->settings['udc_keep_thumbnails']['value'];

        if($attachments) {
            foreach($attachments as $attachment) {
                $registered_file_path = get_attached_file($attachment->ID);
                
                if($keep_thumbnails && count($this->get_image_sizes()) > 0 && substr($attachment->post_mime_type, 0, 5) == 'image') {
                    $extension_position = strrpos($registered_file_path, '.');
                    $registered_file_paths_with_thumbnails = [$registered_file_path];
                    
                    foreach($this->get_image_sizes() as $image_size) {
                        $registered_file_paths_with_thumbnails[] = substr($registered_file_path, 0, $extension_position) . '-' . $image_size . substr($registered_file_path, $extension_position);
                    }

                    $this->registered_file_paths[] = $registered_file_paths_with_thumbnails;
                } else {
                    $this->registered_file_paths[] = $registered_file_path;
                }
            }
        }

        $log_timestamp = time();

        $scan_log_file_relative_path = 'logs/scan/udc_scan_' . $log_timestamp . '.log';
        $scan_log_file_path = $this->plugin_dir_path . $scan_log_file_relative_path;
        $this->public_scan_log_file_path = $this->plugin_dir_url . $scan_log_file_relative_path;
        $scan_log_file = fopen($scan_log_file_path, 'w');

        $unregistered_log_file_relative_path = 'logs/unregistered/udc_unregistered_' . $log_timestamp . '.log';
        $this->unregistered_log_file_path = $this->plugin_dir_path . $unregistered_log_file_relative_path;
        $unregistered_log_file = fopen($this->unregistered_log_file_path, 'w');

        foreach($this->recursive_iterator_items as $item) {
            if($item->isFile()) {
                $file_pathname = $item->getPathname();
                fwrite($scan_log_file, 'Directory File: ' . $file_pathname . PHP_EOL);
                
                $scan_result = in_array_r($file_pathname, $this->registered_file_paths) ? self::SCAN_RESULT_REGISTERED_FILE : self::SCAN_RESULT_UNREGISTERED_FILE;

                switch($scan_result) {
                    case self::SCAN_RESULT_REGISTERED_FILE:
                        fwrite($scan_log_file, 'Scan Result: File registered in Media Library.' . PHP_EOL);
                        break;
                    case self::SCAN_RESULT_UNREGISTERED_FILE:
                        fwrite($scan_log_file, 'Scan Result: Unregistered File.' . PHP_EOL);
                        $this->unregistered_files[] = $item;
                        fwrite($unregistered_log_file, $file_pathname . PHP_EOL);
                        break;
                    default: break;
                }
            }
        }

        fclose($scan_log_file);
        fclose($unregistered_log_file);

        $this->system_max_input_vars = (int)ini_get('max_input_vars');
    }

    // -- Delete Files
    private function delete_files()
    {
        $unregistered_log_file_path = $this->user_input['unregistered_log'];
        $deletes = $this->user_input['deletes'];
        $unregistered_log_file = fopen($unregistered_log_file_path, 'r');
        $unregistered_filenames = explode(PHP_EOL, fread($unregistered_log_file, filesize($unregistered_log_file_path)));
        fclose($unregistered_log_file);

        $delete_log_file_relative_path = 'logs/delete/udc_delete_' . time() . '.log';
        $delete_log_file_path = $this->plugin_dir_path . $delete_log_file_relative_path;
        $this->public_delete_log_file_path = $this->plugin_dir_url . $delete_log_file_relative_path;
        $delete_log_file = fopen($delete_log_file_path, 'w');

        foreach($deletes as $delete_i) {
            $filename = $unregistered_filenames[$delete_i];
            unlink($filename);
            fwrite($delete_log_file, 'Deleting File: ' . $filename . PHP_EOL);
        }

        $delete_empty_directories = (bool)$this->settings['udc_delete_empty_directories']['value'];

        if($delete_empty_directories) {
            $this->delete_empty_directories_r($this->system_upload_dir, $delete_log_file);
        }

        fclose($delete_log_file);
    }

    // -- Archive Directory
    private function archive_directory()
    {
        $zip = new ZipArchive();
        $zip->open($this->system_upload_dir . '/udc_archive_' . time() . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach($this->recursive_iterator_items as $item) {
            if(!$item->isDir()) {
                $zip->addFile($item->getPathname(), substr($item->getPathname(), strlen($this->system_upload_dir) + 1));
            }
        }

        $zip->close();
    }

    // HTML Rendering

    // -- Render Page
    private function render_page()
    {
        ?>
        <div id="udc" class="wrap">
            <h1>Upload Directory Cleaner</h1>
        <?php
        switch($this->get_action()) {
            case self::ACTION_SCAN_DIRECTORY:
                $this->render_scan_page_html();
                break;
            case self::ACTION_DELETE_FILES:
                $this->render_delete_page_html();
                break;
            default:
                $this->render_start_page_html();
                break;
        }
        ?>
        </div>
        <?php
    }

    // -- Action Form
    private function render_action_form_html($action, $submit_text, $function = null, $submit_classes = ['button-primary', 'float-right'])
    {
        ?>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $action; ?>" />
            <?php if($function) call_user_func($function) ?>
            <input class="<?php echo implode(' ', $submit_classes); ?>" type="submit" value="<?php echo $submit_text; ?>" />
        </form>
        <?php
    }

    // -- Upload Directory Form
    private function render_upload_directory_form_html()
    {
        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th>Path</th>
                    <td><?php echo $this->public_upload_dir; ?></td>
                </tr>
                <tr>
                    <th>All Files</th>
                    <td><?php echo $this->file_count; ?></td>
                </tr>
                <tr>
                    <th>Scan Exclude</th>
                    <td>
                        <?php
                        foreach($this->direct_iterator_items as $file) {
                            $filename = $file->getFilename();
                            if(!$file->isDot() && $filename != 'sites') {
                                $checked = !((int)$filename > 1900 && (int)$filename < 2100);

                                ?>
                                <div>
                                    <input type="checkbox" name="excludes[]" value="<?php echo $file->getPathname(); ?>" <?php checked($checked) ?>> <?php echo $file->getFilename(); ?>
                                </div>
                                <?php
                            }
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    // -- Settings Form
    private function render_settings_form_html()
    {
        ?>
        <table class="form-table">
            <tbody>
                <?php
                    foreach($this->settings as $setting_name => $setting)
                    {
                        $setting_value = $setting['value'];
                        $field = [
                            $setting['type'] != 'textarea' ? '<input type="' . $setting['type'] . '"' : '<textarea',
                            'name="' . $setting_name . '"',
                            array_key_exists('placeholder', $setting) ? 'placeholder="' . $setting['placeholder'] . '"' : '',
                            $setting['type'] != 'checkbox' ? 'value="' . $setting_value . '"' : checked($setting_value, true, false),
                            $setting['type'] != 'textarea' ? '/>' : '>' . $setting_value . '</textarea>',
                        ];

                        ?>
                            <tr>
                                <th>
                                    <label for="<?php echo $setting_name; ?>"><?php echo $setting['title']; ?></label>
                                    <?php
                                        if($setting['description']) {
                                            $description = call_user_func([$this, $setting['description']]) ? call_user_func([$this, $setting['description']]) : $setting['description'];

                                            ?>
                                            <br><small class="udc-setting-description"><?php echo $description; ?></small>
                                            <?php
                                        }
                                    ?>
                                </th>
                                <td><?php echo implode(' ', $field); ?></td>
                            </tr>
                        <?php
                    }
                ?>
            </tbody>
        </table>
        <?php
    }

    // -- Delete Form
    private function render_delete_form_html()
    {
        ?>
        <input type="hidden" name="unregistered_log" value="<?php echo $this->unregistered_log_file_path; ?>" />
        <input type="hidden" name="preg_excludes" value="<?php echo $this->get_preg_excludes(); ?>" />
        <?php
        foreach($this->unregistered_files as $index => $file) {
            if($index == $this->system_max_input_vars - 2)
                break;

            ?>
            <div>
                <input type="checkbox" name ="deletes[]" value="<?php echo $index; ?>" <?php checked(true) ?> /> <?php echo $file->getPathname(); ?>
            </div>
            <?php
        }
    }

    // -- Start Page
    private function render_start_page_html()
    {
        ?>
        <p>Upload Directory Cleaner scans through your Wordpress upload directory searching for all files not being registered in your Media Library. After the scan, choose which files to delete from the list showing all unregistered files.<br><strong>It is highly recommended to backup your upload directory! You can use the "Archive Directory" button to do so.</strong></p>
        <div class="udc-flex-container">
            <div class="udc-box">
                <h3>Upload Directory</h3>
                <?php $this->render_action_form_html(self::ACTION_SCAN_DIRECTORY, 'Scan Directory', [$this, 'render_upload_directory_form_html']); ?>
                <?php $this->render_action_form_html(self::ACTION_ARCHIVE_DIRECTORY, 'Archive Directory', null, ['button-secondary']); ?>
            </div>
            <div class="udc-box">
                <h3>Settings</h3>
                <?php $this->render_action_form_html(self::ACTION_SAVE_SETTINGS, 'Save Settings', [$this, 'render_settings_form_html']); ?>
            </div>
        </div>
        <?php
    }

    // -- Scan Page
    private function render_scan_page_html()
    {
        ?>
        <h3>Directory Scan</h3>
        <p>Scanning <?php echo $this->file_count; ?> files...</p>
        <iframe class="udc-log-window" src="<?php echo $this->public_scan_log_file_path; ?>"></iframe>
        <p>Scanning finished.</p>
        <h3>Scan Result</h3>
        <p>There are <?php echo count($this->unregistered_files); ?> unregistered files (<?php echo formatSizeUnits($this->get_unregistered_files_size()); ?>) in the upload directory.<br><strong><?php echo count($this->unregistered_files) > 0 ? 'Unselect all files you do not want to be deleted!' : 'Nice, your upload directory looks clean!'; ?></strong></p>
        <?php
            if(count($this->unregistered_files) > $this->system_max_input_vars) {
                ?>
                <h4>Note: Your server currently supports a maximum of <?php echo $this->system_max_input_vars - 2; ?> deletions at once. Make sure to rescan afterwards or ask your server administrator to increase 'max_input_vars'.</h4>
                <?php
            }
        ?>
        <?php
        if(count($this->unregistered_files) > 0) {
            $this->render_action_form_html('delete_files', 'Delete Selected Files', [$this, 'render_delete_form_html']);
        } else {
            $this->render_action_form_html('finish', 'Finish');
        }
        ?>
        <?php
    }

    // -- Delete Page
    private function render_delete_page_html()
    {
        ?>
        <h3>Delete Files</h3>
        <p>Deleting <?php echo count($this->user_input['deletes']); ?> files...</p>
        <iframe class="udc-log-window" src="<?php echo $this->public_delete_log_file_path; ?>"></iframe>
        <p>Deleting finished.</p>
        <?php $this->render_action_form_html('finish', 'Finish'); ?>
        <?php
    }
}

$UDC = UDC::getInstance();

register_activation_hook(__FILE__, ['UDC', 'install']);

// Helpers
function in_array_r($needle, $haystack, $strict = false) {
    foreach ($haystack as $item) {
        if (($strict ? $item === $needle : $item == $needle) || (is_array($item) && in_array_r($needle, $item, $strict))) {
            return true;
        }
    }

    return false;
}

function formatSizeUnits($bytes)
{
    if ($bytes >= 1073741824)
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    elseif ($bytes >= 1048576)
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    elseif ($bytes >= 1024)
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    elseif ($bytes > 1)
        $bytes = $bytes . ' bytes';
    elseif ($bytes == 1)
        $bytes = $bytes . ' byte';
    else
        $bytes = '0 bytes';

    return $bytes;
}
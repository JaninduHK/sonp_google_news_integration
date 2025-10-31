<?php
/**
 * Plugin Name: Elementor News Widget
 * Description: Auto-updating Google News-style widget for Elementor (with AI dek rewriting in inverted-pyramid style).
 * Version: 1.2.0
 * Author: Janindu Hansaka
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------- Boot ---------------- */

add_action( 'plugins_loaded', function () {
    // Ensure Elementor is active
    if ( ! did_action( 'elementor/loaded' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Elementor News Widget</strong> requires Elementor to be installed and active.</p></div>';
        } );
        return;
    }

    // Register widget
    add_action( 'elementor/widgets/register', function( $widgets_manager ) {
        require_once __DIR__ . '/includes/class-news-widget.php';
        $widgets_manager->register( new \Elementor_News_Widget() );
    } );

    // Styles (front + editor)
    $enqueue = function () {
        wp_enqueue_style(
            'enw-style',
            plugin_dir_url( __FILE__ ) . 'assets/css/style.css',
            [],
            '1.2.0'
        );
    };
    add_action( 'wp_enqueue_scripts', $enqueue );
    add_action( 'elementor/editor/before_enqueue_scripts', $enqueue );
} );


/* ---------------- Settings Page (API key & AI options) ---------------- */

add_action( 'admin_menu', function () {
    add_options_page(
        'Elementor News Widget',
        'Elementor News Widget',
        'manage_options',
        'elementor-news-widget',
        'enw_settings_page_render'
    );
} );

add_action( 'admin_init', function () {
    register_setting( 'enw_settings', 'enw_openai_api_key' );
    register_setting( 'enw_settings', 'enw_ai_enabled',    ['type'=>'boolean','default'=>false] );
    register_setting( 'enw_settings', 'enw_ai_model',      ['type'=>'string', 'default'=>'gpt-4o-mini'] );
    register_setting( 'enw_settings', 'enw_ai_temperature',['type'=>'number', 'default'=>0.3 ] );
    register_setting( 'enw_settings', 'enw_ai_max_tokens', ['type'=>'integer','default'=>120 ] );
} );

function enw_settings_page_render() {
    if ( ! current_user_can( 'manage_options' ) ) return; ?>
    <div class="wrap">
        <h1>Elementor News Widget — AI Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'enw_settings' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="enw_ai_enabled">Enable AI Rewriting</label></th>
                    <td>
                        <input type="checkbox" name="enw_ai_enabled" id="enw_ai_enabled" value="1" <?php checked( get_option('enw_ai_enabled'), 1 ); ?> />
                        <p class="description">Rewrite the sub description (“dek”) of each news item into inverted-pyramid style using ChatGPT.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="enw_openai_api_key">OpenAI API Key</label></th>
                    <td>
                        <input type="password" class="regular-text" name="enw_openai_api_key" id="enw_openai_api_key" value="<?php echo esc_attr( get_option('enw_openai_api_key','') ); ?>" />
                        <p class="description">Create at <a href="https://platform.openai.com/" target="_blank" rel="noopener">OpenAI</a>. Stored in WordPress options.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="enw_ai_model">Model</label></th>
                    <td>
                        <input type="text" class="regular-text" name="enw_ai_model" id="enw_ai_model" value="<?php echo esc_attr( get_option('enw_ai_model','gpt-4o-mini') ); ?>" />
                        <p class="description">Examples: <code>gpt-4o-mini</code> (good), <code>gpt-4o</code> (stronger), <code>gpt-3.5-turbo</code> (budget).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="enw_ai_temperature">Temperature</label></th>
                    <td>
                        <input type="number" step="0.1" min="0" max="1" name="enw_ai_temperature" id="enw_ai_temperature" value="<?php echo esc_attr( get_option('enw_ai_temperature',0.3) ); ?>" />
                        <p class="description">Lower is more factual/concise (recommended 0.2–0.4).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="enw_ai_max_tokens">Max Tokens</label></th>
                    <td>
                        <input type="number" min="32" max="400" name="enw_ai_max_tokens" id="enw_ai_max_tokens" value="<?php echo esc_attr( get_option('enw_ai_max_tokens',120) ); ?>" />
                        <p class="description">Short, ~1–2 sentence dek is ideal.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
<?php }

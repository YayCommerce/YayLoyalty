<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\License;

use YayLoyaltyScoped\YayCommerce\AdminShell\License\Contracts\LicenseConfigAdapter;
use YayLoyaltyScoped\YayCommerce\AdminShell\Support\Slug;
defined('ABSPATH') || exit;
/**
 * Per-plugin REST routes for license management.
 *
 * REST namespace: {slug}/v1 (derived from adapter slug).
 * Ported from YayMail Pro RestAPI.php — CorePlugin::get() replaced with adapter.
 */
class RestAPI
{
    protected LicenseConfigAdapter $adapter;
    public function __construct(LicenseConfigAdapter $adapter)
    {
        $this->adapter = $adapter;
        add_action('rest_api_init', [$this, 'init_rest_api']);
    }
    public function init_rest_api(): void
    {
        $namespace = Slug::to_var_name($this->adapter->get_plugin_slug()) . '/v1';
        register_rest_route($namespace, '/license/activate', ['methods' => [\WP_REST_Server::CREATABLE], 'callback' => [$this, 'activate_license'], 'permission_callback' => [$this, 'permission_callback']]);
        register_rest_route($namespace, '/license/update', ['methods' => [\WP_REST_Server::CREATABLE], 'callback' => [$this, 'update_license'], 'permission_callback' => [$this, 'permission_callback']]);
        register_rest_route($namespace, '/license/delete', ['methods' => [\WP_REST_Server::CREATABLE], 'callback' => [$this, 'remove_license'], 'permission_callback' => [$this, 'permission_callback']]);
    }
    public function activate_license(\WP_REST_Request $request_data): \WP_REST_Response
    {
        $nonce = $request_data->get_header('x_wp_nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_REST_Response(['success' => \false, 'message' => 'Nonce is invalid'], 403);
        }
        $license_key = \sanitize_text_field($request_data->get_param('license_key'));
        $license = new License($this->adapter);
        $activate_response = $license->activate($license_key);
        $return = ['success' => $activate_response['success'], 'name' => $this->adapter->get_plugin_name(), 'slug' => $this->adapter->get_plugin_slug()];
        if ($activate_response['success']) {
            $_plugin = ['slug' => $this->adapter->get_plugin_slug(), 'name' => $this->adapter->get_plugin_name()];
            ob_start();
            include __DIR__ . '/../../views/license-card.php';
            $return['html'] = ob_get_clean();
        } else {
            $return['message'] = LicenseAPI::get_error_message($activate_response['message'] ?? '');
        }
        return new \WP_REST_Response($return);
    }
    public function update_license(\WP_REST_Request $request_data): \WP_REST_Response
    {
        $nonce = $request_data->get_header('x_wp_nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_REST_Response(['success' => \false, 'message' => 'Nonce is invalid'], 403);
        }
        $license = new License($this->adapter);
        $update_response = $license->update();
        // The key survives a successful check, an 'expired' answer and a server error.
        $key_kept = $license->is_active();
        $return = [
            // An 'expired' answer is a completed check: the card shows the expired state.
            'success' => $update_response['success'] || $key_kept && empty($update_response['is_server_error']),
            'name' => $this->adapter->get_plugin_name(),
            'slug' => $this->adapter->get_plugin_slug(),
        ];
        $_plugin = ['slug' => $this->adapter->get_plugin_slug(), 'name' => $this->adapter->get_plugin_name()];
        if ($key_kept) {
            ob_start();
            include __DIR__ . '/../../views/license-card.php';
            $return['html'] = ob_get_clean();
        } else {
            ob_start();
            include __DIR__ . '/../../views/license-activate-card.php';
            $return['html'] = ob_get_clean();
        }
        return new \WP_REST_Response($return);
    }
    public function remove_license(\WP_REST_Request $request_data): \WP_REST_Response
    {
        $nonce = $request_data->get_header('x_wp_nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_REST_Response(['success' => \false, 'message' => 'Nonce is invalid'], 403);
        }
        $license = new License($this->adapter);
        $license->remove();
        $return = ['success' => \true, 'name' => $this->adapter->get_plugin_name(), 'slug' => $this->adapter->get_plugin_slug()];
        $_plugin = $return;
        ob_start();
        include __DIR__ . '/../../views/license-activate-card.php';
        $return['html'] = ob_get_clean();
        return new \WP_REST_Response($return);
    }
    public function permission_callback(): bool
    {
        return current_user_can($this->adapter->get_capability());
    }
}

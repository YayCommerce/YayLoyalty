<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Registry;

use YayLoyaltyScoped\YayCommerce\AdminShell\License\Contracts\LicenseConfigAdapter;
/**
 * Minimal LicenseConfigAdapter built from addon filter data.
 * Used by AddonBridge to satisfy License constructor and card templates
 * without requiring a full plugin adapter implementation.
 */
class AddonLicenseAdapter implements LicenseConfigAdapter
{
    private string $slug;
    private string $name;
    private string $basename;
    private string $file;
    private string $url;
    private int $item_id;
    private string $store_url;
    public function __construct(array $plugin_data)
    {
        $this->slug = $plugin_data['slug'] ?? '';
        $this->name = $plugin_data['name'] ?? $this->slug;
        $this->basename = $plugin_data['basename'] ?? '';
        $this->file = $plugin_data['file'] ?? '';
        $this->url = $plugin_data['url'] ?? '';
        $this->item_id = (int) ($plugin_data['item_id'] ?? 0);
        $this->store_url = 'https://yaycommerce.com/';
    }
    public function get_plugin_slug(): string
    {
        return $this->slug;
    }
    public function get_plugin_name(): string
    {
        return $this->name;
    }
    public function get_plugin_version(): string
    {
        return '';
    }
    public function get_plugin_file(): string
    {
        return $this->file;
    }
    public function get_item_id(): int
    {
        return $this->item_id;
    }
    public function get_store_url(): string
    {
        return $this->store_url;
    }
    public function get_store_link(): string
    {
        return $this->url;
    }
    public function get_menu_title(): string
    {
        return $this->name;
    }
    public function get_page_title(): string
    {
        return $this->name;
    }
    public function get_menu_slug(): string
    {
        return '';
    }
    public function get_settings_page_callback(): ?callable
    {
        return null;
    }
    public function get_settings_page_position(): ?int
    {
        return null;
    }
    public function get_capability(): string
    {
        return 'manage_options';
    }
    public function get_plugin_basename(): string
    {
        return $this->basename;
    }
    public function get_settings_label(): string
    {
        return '';
    }
    public function get_docs_url(): string
    {
        return '';
    }
    public function get_pro_url(): string
    {
        return '';
    }
}

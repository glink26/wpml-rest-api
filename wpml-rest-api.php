<?php
/*
Plugin Name: WPML REST API (Enhanced)
Version: 2.0.3
Description: Adds translations information to posts, categories, and tags in the WP REST API for sites running WPML.
Author: Shawn Hooper
*/

namespace ShawnHooper\WPML;

use WP_REST_Request;
use RuntimeException;

class WPML_REST_API
{
    private array $translations = [];

    public function wordpress_hooks(): void
    {
        add_action('rest_api_init', [$this, 'init'], 1000);
    }

    public function init(): void
    {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');

        if (!is_plugin_active('sitepress-multilingual-cms/sitepress.php')) {
            return;
        }

        $available_languages = wpml_get_active_languages_filter('', ['skip_missing' => false]);
        if ((!empty($available_languages) && !isset($GLOBALS['icl_language_switched'])) || !$GLOBALS['icl_language_switched']) {
            if (isset($_REQUEST['wpml_lang'])) {
                $lang = $_REQUEST['wpml_lang'];
            } elseif (isset($_REQUEST['lang'])) {
                $lang = $_REQUEST['lang'];
            }

            if (isset($lang) && array_key_exists($lang, $available_languages)) {
                do_action('wpml_switch_language', $lang);
            }
        }

        // 为所有 post type 注册字段
        $post_types = get_post_types(array('public' => true, 'exclude_from_search' => false));
        foreach ($post_types as $post_type) {
            $this->register_post_type_api_fields($post_type);
        }

        // 为所有 taxonomy 注册字段 (包括 category 和 post_tag)
        $taxonomies = get_taxonomies(['public' => true]);
        foreach ($taxonomies as $taxonomy) {
            $this->register_taxonomy_api_fields($taxonomy);
        }
    }

    private function register_post_type_api_fields(string $post_type): void
    {
        register_rest_field($post_type, 'wpml_current_locale', [
            'get_callback' => [$this, 'get_current_locale'],
            'update_callback' => null,
            'schema' => null,
        ]);

        register_rest_field($post_type, 'wpml_translations', [
            'get_callback' => [$this, 'get_post_translations'],
            'update_callback' => null,
            'schema' => null,
        ]);
    }

    private function register_taxonomy_api_fields(string $taxonomy): void
    {
        register_rest_field($taxonomy, 'wpml_translations', [
            'get_callback' => [$this, 'get_term_translations'],
            'update_callback' => null,
            'schema' => null,
        ]);
    }

    public function get_current_locale(array $object, string $field_name, WP_REST_Request $request): string
    {
        $langInfo = wpml_get_language_information($object);
        if (is_wp_error($langInfo)) {
            throw new RuntimeException('Unable to retrieve the current locale');
        }
        return $langInfo['locale'];
    }

    public function get_post_translations(array $object, string $field_name, WP_REST_Request $request): array
    {
        $this->translations = [];
        $languages = apply_filters('wpml_active_languages', null);

        if (!$languages) {
            return [];
        }

        foreach ($languages as $language) {
            $this->add_post_translation($object, $language);
        }

        return $this->translations;
    }

    private function add_post_translation(array $object, array $language): void
    {
        $post_id = wpml_object_id_filter($object['id'], $object['type'], false, $language['language_code']);
        if (!$post_id || $post_id === $object['id']) {
            return;
        }

        $translated_post = get_post($post_id);
        if ($translated_post) {
            $this->translations[$language['default_locale']] = [
                'locale'     => $language['default_locale'],
                'id'         => $translated_post->ID,
                'slug'       => $translated_post->post_name,
                'post_title' => $translated_post->post_title,
                'href'       => get_permalink($translated_post),
            ];
        }
    }

    public function get_term_translations(array $object, string $field_name, WP_REST_Request $request): array
    {
        $translations = [];
        $languages = apply_filters('wpml_active_languages', null);

        if (!$languages || empty($object['id']) || empty($object['taxonomy'])) {
            return [];
        }

        foreach ($languages as $language) {
            $term_id = apply_filters('wpml_object_id', $object['id'], $object['taxonomy'], false, $language['language_code']);
            if ($term_id && $term_id !== $object['id']) {
                $term = get_term($term_id, $object['taxonomy']);
                if ($term && !is_wp_error($term)) {
                    $translations[$language['default_locale']] = [
                        'locale' => $language['default_locale'],
                        'id'     => $term->term_id,
                        'slug'   => $term->slug,
                        'name'   => $term->name,
                        'href'   => get_term_link($term),
                    ];
                }
            }
        }

        return $translations;
    }
}

$GLOBALS['WPML_REST_API'] = new WPML_REST_API();
$GLOBALS['WPML_REST_API']->wordpress_hooks();

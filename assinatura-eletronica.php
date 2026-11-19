<?php
/**
 * Plugin Name: Assinatura Eletrônica Educacional
 * Plugin URI: https://github.com/paulommaeda/assinatura-eletronica
 * Description: Emita contratos escolares, registre a ciência do responsável e acompanhe a assinatura por código de e-mail.
 * Version: 1.0.1
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Author: Paulo Maeda
 * Update URI: https://github.com/paulommaeda/assinatura-eletronica
 * License: GPL-2.0-or-later
 */
namespace EduSign;
defined('ABSPATH') || exit;

const VERSION = '1.0.1';
const FILE = __FILE__;
const DIR = __DIR__;
const REPO = 'https://github.com/paulommaeda/assinatura-eletronica';
const CAP = 'manage_edu_contracts';

foreach (['store','activator','templates','pdf','audit','contracts','delivery','portal-service','rest','updater'] as $part) {
    require_once DIR . '/includes/' . $part . '.php';
}
require_once DIR . '/admin/admin.php';
require_once DIR . '/public/portal.php';

register_activation_hook(FILE, [Activator::class, 'activate']);
register_deactivation_hook(FILE, [Activator::class, 'deactivate']);
add_action('plugins_loaded', [Activator::class, 'upgrade']);
add_filter('cron_schedules', [Activator::class, 'schedules']);
add_action('edu_sign_worker', [Delivery::class, 'work']);
add_action('edu_sign_maintenance', [Contracts::class, 'maintenance']);
add_action('rest_api_init', [Rest::class, 'register']);
add_action('template_redirect', [Portal::class, 'page'], 0);
add_action('admin_menu', [Admin::class, 'menu']);
add_action('admin_post_edu_sign_manage', [Admin::class, 'submit']);
add_action('admin_post_edu_sign_document', [Admin::class, 'download']);
add_action('admin_enqueue_scripts', [Admin::class, 'assets']);
Updater::register();

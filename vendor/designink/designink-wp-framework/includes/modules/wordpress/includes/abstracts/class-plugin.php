<?php
/**
 * DesignInk WordPress Framework
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to answers@designinkdigital.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the plugin to newer
 * versions in the future. If you wish to customize the plugin for your
 * needs please refer to https://designinkdigital.com
 *
 * @package   DesignInk/WordPress/Framework
 * @author    DesignInk Digital
 * @copyright Copyright (c) 2008-2021, DesignInk, LLC
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

namespace DesignInk\WordPress\Framework\v1_1_0;

defined( 'ABSPATH' ) or exit;

use DesignInk\WordPress\Framework\v1_1_0\Admin_Module;
use DesignInk\WordPress\Framework\v1_1_0\Framework;
use DesignInk\WordPress\Framework\v1_1_0\Module;
use DesignInk\WordPress\Framework\v1_1_0\Plugin_Upgrader_Trait;
use DesignInk\WordPress\Framework\v1_1_0\Post_Type;

if ( ! class_exists( '\DesignInk\WordPress\Framework\v1_1_0\Plugin', false ) ) {

	/**
	 * A class to represent and help deal with common plugin functionality.
	 */
	abstract class Plugin extends Module {

		// Use Plugin Upgrader trait
		use Plugin_Upgrader_Trait;

		/** @var \DesignInk\WordPress\Framework\v1_1_0\Admin_Module The admin module, if loaded. */
		protected $admin_module;

		/** @var string The default directory for loading templates. */
		protected static $templates_dir = 'templates';

		/** @var string The default directory for loading post types. (Will be a subfolder of includes) */
		protected static $post_types_dir = 'post-types';

		/** @var array The Post Types loaded with this plugin. */
		protected $loaded_post_types = array();

		/**
		 * Get the Admin Module.
		 * 
		 * @return null|\DesignInk\WordPress\Framework\v1_1_0\Admin_Module The Admin Module.
		 */
		final public function get_admin_module() { return $this->admin_module; }

		/**
		 * A protected constructor to ensure only singleton instances of plugins exist.
		 * 
		 * @param bool $is_submodule Whether or not the Plugin is an Admin Module.
		 */
		protected function __construct( bool $is_submodule = false ) {
			$reflection = $this->get_class_reflection();
			$file = $reflection->getFileName();

			register_activation_hook( $file, array( static::class, 'activation' ) );
			register_deactivation_hook( $file, array( static::class, 'deactivation' ) );
			register_activation_hook( $file, array( __CLASS__, 'activation' ) );
			register_deactivation_hook( $file, array( __CLASS__, 'deactivation' ) );

			// If this instance directly inherits \DesignInk\WordPress\Framework\v1_1_0.
			if ( is_subclass_of( $this, self::class ) ) {
				Framework::instance()->register_plugin( $this );
				$this->maybe_init_admin();
			}

			parent::__construct( $is_submodule );
			$this->init_upgrader();
			$this->register_available_post_types();
		}

		/**
		 * A default, empty function to run on plugin activation.
		 */
		public static function activation() {
			flush_rewrite_rules();
		}

		/**
		 * A default, empty function to run on plugin deactivation.
		 */
		public static function deactivation() {  }

		/**
		 * A function to retrieve a template file from the templates directory.
		 * 
		 * @param string $template The template slug to retrieve and require.
		 * @param array $args The variables to set locally for the template.
		 */
		final public function get_template( string $template, array $args = array() ) {
			$template_path = sprintf( '%s%s/%s-template.php', plugin_dir_path( $this->get_class_reflection()->getFileName() ), static::$templates_dir, $template );

			if ( is_file( $template_path ) ) {
				foreach ( $args as $var => $value ) {
					// Set the variable locally by key
					${ $var } = $value;
				}

				include ( $template_path );
			} else {
				$message = sprintf( "Tried to load template %s from %s, but failed.", $template, $template_path );
				Utility::doing_it_wrong( __METHOD__, $message );
			}

		}

		/**
		 * Enqueue a script from the assets folder.
		 * 
		 * @param string $script			The script file name (including extension).
		 * @param array $reqs				The required Javascript files by reference.
		 * @param array $locatization_data	Any localization data to pass to the script.
		 */
		final public function enqueue_js( string $script, array $reqs = array(), array $locatization_data = array() ) {
			$plugin_class_info = $this->get_class_reflection();
			$scripts_directory_url = plugins_url( 'assets/js', $plugin_class_info->getFileName() );
			$script_slug = Utility::slugify( $script );
			wp_enqueue_script( $script_slug, sprintf( '%s/%s.js', $scripts_directory_url, $script ), $reqs );

			if ( ! empty( $locatization_data ) ) {
				foreach ( $locatization_data as $key => $data ) {
					wp_localize_script( $script_slug, $key, $data );
				}
			}
		}

		/**
		 * Enqueue a stylesheet from the assets folder.
		 * 
		 * @param string $style The stylesheet file name (including extension).
		 * @param array $reqs The required CSS files by reference.
		 */
		final public function enqueue_css( string $style, array $reqs = array() ) {
			$plugin_class_info = $this->get_class_reflection();
			$styles_directory_url = plugins_url( 'assets/css', $plugin_class_info->getFileName() );
			$style_slug = Utility::slugify( $style );
			wp_enqueue_style( $style_slug, sprintf( '%s/%s.css', $styles_directory_url, $style ), $reqs );
		}

		/**
		 * Look for the admin module and load it. It was decided the admin module should stay in \DesignInk\WordPress\Framework\v1_1_0 and not belong in \DesignInk\WordPress\Framework\v1_1_0\Module
		 * because it may be cleaner to isolate all of the admin code to one area so we don't have to look for admin functionality in admin and non-admin modules. Admin extensions
		 * should then only be available to root plugins.
		 */
		final private function maybe_init_admin() {
			$reflection = $this->get_class_reflection();
			$file_path = sprintf( '%sadmin/%s-admin.php', plugin_dir_path( $reflection->getFileName() ), Utility::slugify( $reflection->getShortName() ) );

			if ( is_file( $file_path ) ) {
				require_once ( $file_path );
				$admin_module_name = sprintf( '%s_Admin', $reflection->getName() );

				if ( class_exists( $admin_module_name, false ) && is_subclass_of( $admin_module_name, Admin_Module::class ) ) {
					$Admin_Module = $admin_module_name::submodule_instance();
					$this->admin_module = $Admin_Module;
					$admin_module_info = new \ReflectionClass( $Admin_Module );
					$this->loaded_modules[ $admin_module_info->getName() ] = $Admin_Module;
				} elseif ( ! class_exists( $admin_module_name ) ) {
					$message = sprintf( "Could not find admin module, %s.", $admin_module_name );
					Utility::doing_it_wrong( __METHOD__, __( $message ) );
				} else {
					$message = sprintf( "Found admin module class, %s, but it may not be correctly extending %s.", $admin_module_name, Admin_Module::class );
					Utility::doing_it_wrong( __METHOD__, __( $message ) );
				}

			}
		}

		/**
		 * Search for \DesignInk\WordPress\Framework\v1_1_0\Post_Type classes in the Plugin { static::$post_types_dir } and register them.
		 */
		final private function register_available_post_types() {
			$reflection = $this->get_class_reflection();
			$post_types_dir = sprintf( '%s%s/%s/', plugin_dir_path( $reflection->getFileName() ), static::$includes_dir, static::$post_types_dir );

			if ( is_dir( $post_types_dir ) ) {
				$folder_files = Utility::scandir( $post_types_dir, 'files' );

				foreach ( $folder_files as $file ) {
					$module_path = sprintf( '%s%s', $post_types_dir, $file );
					$module_name = $this->load_class_file( $module_path );

					if ( false !== $module_name ) {

						if ( is_subclass_of( $module_name, Post_Type::class ) ) {
							$Module = $module_name::instance();
							$Module_Reflection = new \ReflectionClass( $Module );
							$this->loaded_post_types[  $Module_Reflection->getShortName() ] = $Module;
						} else {
							$message = sprintf( "Successfully found class, '%s', but it does not appear to be a Post Type, make sure you are implementing %s in '%s'.", $module_name, Post_Type::class, $module_path );
							Utility::doing_it_wrong( __METHOD__, __( $message ) );
						}

					}
				}
			}
		}

		/**
		 * Force deactivate this Plugin by updating the WordPress options. There was an 'activate' function, but you must require the Plugin file before using the class anyway.
		 */
		final public function deactivate() {
			$current = get_option( 'active_plugins', array() );

			if ( $key = array_search( $this->get_plugin_id(), $current ) ) {
				array_splice( $current, $key, 1 );
			}

			update_option( 'active_plugins', $current );
		}

		/**
		 * Return the file location of the Plugin instance.
		 * 
		 * @return string The plugin file location.
		 */
		final public function get_plugin_file() {
			return $this->get_class_reflection()->getFileName();
		}

		/**
		 * Return the Plugin site ID, which should be the plugin folder name and the name of the PHP file.
		 * 
		 * @return string The Plugin ID.
		 */
		final public function get_plugin_id() {
			return plugin_basename( $this->get_plugin_file() );
		}

	}

}

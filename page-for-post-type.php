<?php
/**
 * Plugin Name: Page for post type
 * Plugin URI: https://github.com/humanmade/page-for-post-type
 * Description: Allows you to set pages for any custom post type archive
 * Version: 0.1
 * Author: Human Made Limited
 * Author URI: http://hmn.md
 */

add_action( 'plugins_loaded', array( 'Page_For_Post_Type', 'get_instance' ) );

class Page_For_Post_Type {

	public static $excludes = array();

	protected static $instance;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	public function __construct() {

		// admin init
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// update post type objects
		add_action( 'registered_post_type', array( $this, 'update_post_type' ), 11, 2 );

		// menu classes
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_wp_nav_menu_objects' ), 1, 2 );

		// customiser
		add_action( 'customize_register', array( $this, 'action_customize_register' ) );

	}

	public function admin_init() {

		// add settings fields

		$cpts = get_post_types( array(), 'objects' );

		add_settings_section( 'page_for_post_type', __( 'Pages for post type archives', 'pfpt' ), '__return_false', 'reading' );

		// add to excludes
		self::$excludes[] = get_option( 'page_for_posts' );

		foreach ( $cpts as $cpt ) {

			if ( ! $cpt->has_archive ) {
				continue;
			}

			$id    = "page_for_{$cpt->name}";
			$value = get_option( $id );

			// keep track of unavailable pages for selection
			if ( $value ) {
				self::$excludes[] = $value;
			}

			// flush rewrite rules when the option is changed
			register_setting( 'reading', $id, function ( $new_value ) use ( $value ) {
				if ( $new_value !== $value ) {
					flush_rewrite_rules();
				}
				return intval( $new_value );
			} );

			add_settings_field( $id, $cpt->labels->name, array( $this, 'cpt_field' ), 'reading', 'page_for_post_type', array(
				'name'      => $id,
				'post_type' => $cpt,
				'value'     => $value
			) );

		}

	}

	public function cpt_field( $args ) {

		wp_dropdown_pages( array(
			'name'             => esc_attr( $args['name'] ),
			'id'               => esc_attr( $args['name'] . '_dropdown' ),
			'selected'         => intval( $args['value'] ),
			'show_option_none' => sprintf( __( 'Default (/%s/)' ), is_string( $args['post_type']->has_archive ) ?
				$args['post_type']->has_archive :
				$args['post_type']->name ),
			'exclude'          => $this->get_excludes( $args['value'] )
		) );

	}

	public function action_customize_register( WP_Customize_Manager $wp_customize ) {

		$cpts = get_post_types( array(), 'objects' );

		// add to excludes
		self::$excludes[] = get_option( 'page_for_posts' );

		$wp_customize->add_section( 'page_for_post_type', array(
			'title' => __( 'Pages for post type archives', 'pfpt' ),
		) );

		foreach ( $cpts as $cpt ) {

			if ( ! $cpt->has_archive ) {
				continue;
			}

			$id    = "page_for_{$cpt->name}";
			$value = get_option( $id );

			// keep track of unavailable pages for selection
			if ( $value ) {
				self::$excludes[] = $value;
			}

			$wp_customize->add_setting( $id, array(
				'type'              => 'option',
				'capability'        => 'manage_options',
				'default'           => 0,
				'sanitize_callback' => function ( $new_value ) use ( $value ) {
					if ( $new_value !== $value ) {
						flush_rewrite_rules();
					}
					return intval( $new_value );
				},
			) );
			$wp_customize->add_control( $id, array(
				'type'            => 'dropdown-pages',
				'section'         => 'page_for_post_type', // Required, core or custom.
				'label'           => $cpt->labels->name,
			) );

		}

	}

	public function get_excludes( $value ) {
		$excludes = array_map( 'intval', self::$excludes );
		$excludes = array_filter( $excludes, function ( $exclude ) use ( $value ) {
			return $exclude && intval( $exclude ) !== intval( $value );
		} );
		return $excludes;
	}

	public function update_post_type( $post_type, $args ) {
		global $wp_post_types, $wp_rewrite;

		$post_type_page = get_option( "page_for_{$post_type}" );

		if ( ! $post_type_page ) {
			return;
		}

		// get the old slug
		$args->rewrite = (array) $args->rewrite;
		$old_slug      = isset( $args->rewrite['slug'] ) ? $args->rewrite['slug'] : $post_type;

		// get page slug
		$slug = get_permalink( $post_type_page );
		$slug = str_replace( home_url(), '', $slug );
		$slug = trim( $slug, '/' );

		$args->rewrite     = wp_parse_args( array( 'slug' => $slug ), $args->rewrite );
		$args->has_archive = $slug;

		// rebuild rewrite rules
		if ( is_admin() || '' != get_option( 'permalink_structure' ) ) {

			if ( $args->has_archive ) {
				$archive_slug = $args->has_archive === true ? $args->rewrite['slug'] : $args->has_archive;
				if ( $args->rewrite['with_front'] ) {
					$archive_slug = substr( $wp_rewrite->front, 1 ) . $archive_slug;
				} else {
					$archive_slug = $wp_rewrite->root . $archive_slug;
				}

				add_rewrite_rule( "{$archive_slug}/?$", "index.php?post_type=$post_type", 'top' );
				if ( $args->rewrite['feeds'] && $wp_rewrite->feeds ) {
					$feeds = '(' . trim( implode( '|', $wp_rewrite->feeds ) ) . ')';
					add_rewrite_rule( "{$archive_slug}/feed/$feeds/?$", "index.php?post_type=$post_type" . '&feed=$matches[1]', 'top' );
					add_rewrite_rule( "{$archive_slug}/$feeds/?$", "index.php?post_type=$post_type" . '&feed=$matches[1]', 'top' );
				}
				if ( $args->rewrite['pages'] ) {
					add_rewrite_rule( "{$archive_slug}/{$wp_rewrite->pagination_base}/([0-9]{1,})/?$", "index.php?post_type=$post_type" . '&paged=$matches[1]', 'top' );
				}
			}

			$permastruct_args         = $args->rewrite;
			$permastruct_args['feed'] = $permastruct_args['feeds'];

			// support plugins that enable 'permastruct' option
			if ( isset( $args->rewrite['permastruct'] ) ) {
				$permastruct = str_replace( $old_slug, $slug, $args->rewrite['permastruct'] );
			} else {
				$permastruct = "{$args->rewrite['slug']}/%$post_type%";
			}

			add_permastruct( $post_type, $permastruct, $permastruct_args );

		}

		// update the global
		$wp_post_types[ $post_type ] = $args;
	}

	/**
	 * Make sure menu items for our pages get the correct classes assigned
	 *
	 * @param $sorted_items
	 * @param $args
	 * @return array
	 */
	public function filter_wp_nav_menu_objects( $sorted_items, $args ) {

		$post_types     = get_post_types( array(), 'objects' );
		$page_ids       = array();
		$queried_object = get_queried_object();

		if ( ! $queried_object ) {
			return $sorted_items;
		}

		$object_post_type = false;

		if ( is_singular() ) {
			$object_post_type = $queried_object->post_type;
		}

		if ( is_post_type_archive() ) {
			$object_post_type = $queried_object->name;
		}

		if ( ! $object_post_type ) {
			return $sorted_items;
		}

		foreach ( $post_types as $post_type ) {
			if ( ! $post_type->has_archive && 'post' !== $post_type->name ) {
				continue;
			}

			if ( 'post' === $post_type->name ) {
				$page_id = get_option( 'page_for_posts' );
			} else {
				$page_id = get_option( "page_for_{$post_type->name}" );
			}

			if ( ! $page_id ) {
				continue;
			}

			$page_ids[ $post_type->name ] = $page_id;
		}

		if ( ! isset( $page_ids[ $object_post_type ] ) ) {
			return $sorted_items;
		}

		foreach ( $sorted_items as &$item ) {
			if ( $item->type === 'post_type' && $item->object === 'page' && intval( $item->object_id ) === intval( $page_ids[ $object_post_type ] ) ) {
				if ( is_singular( $object_post_type ) ) {
					$item->classes[]             = 'current-menu-item-ancestor';
					$item->current_item_ancestor = true;
					$sorted_items                = $this->add_ancestor_class( $item, $sorted_items );
				}
				if ( is_post_type_archive( $object_post_type ) ) {
					$item->classes[]    = 'current-menu-item';
					$item->current_item = true;
					$sorted_items       = $this->add_ancestor_class( $item, $sorted_items );
				}
			}
		}

		return $sorted_items;
	}

	/**
	 * Recursively set the ancestor class
	 *
	 * @param object $child
	 * @param array  $items
	 * @return array
	 */
	protected function add_ancestor_class( $child, $items ) {

		if ( ! intval( $child->menu_item_parent ) ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( intval( $item->ID ) === intval( $child->menu_item_parent ) ) {
				$item->classes[]             = 'current-menu-item-ancestor';
				$item->current_item_ancestor = true;
				if ( intval( $item->menu_item_parent ) ) {
					$items = $this->add_ancestor_class( $item, $items );
				}
				break;
			}
		}

		return $items;
	}

}

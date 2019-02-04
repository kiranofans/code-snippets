<?php

namespace Code_Snippets;

use function Code_Snippets\Settings\get_setting;

/**
 * This class handles the add/edit menu
 */
class Edit_Menu extends Admin_Menu {

	/**
	 * The snippet object currently being edited
	 * @var Snippet
	 * @see Edit_Menu::load_snippet_data()
	 */
	protected $snippet = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'edit',
			_x( 'Edit Snippet', 'menu label', 'code-snippets' ),
			__( 'Edit Snippet', 'code-snippets' )
		);
	}

	/**
	 * Register action and filter hooks
	 */
	public function run() {
		parent::run();
		$this->remove_debug_bar_codemirror();
	}

	/**
	 * Register the admin menu
	 */
	public function register() {

		/* Add edit menu if we are currently editing a snippet */
		if ( isset( $_REQUEST['page'] ) && code_snippets()->get_menu_slug( 'edit' ) === $_REQUEST['page'] ) {
			parent::register();
		}

		/* Add New Snippet menu */
		$this->add_menu(
			code_snippets()->get_menu_slug( 'add' ),
			_x( 'Add New', 'menu label', 'code-snippets' ),
			__( 'Add New Snippet', 'code-snippets' )
		);
	}

	/**
	 * Executed when the menu is loaded
	 */
	public function load() {
		parent::load();

		// Disallow visiting the edit snippet page without a valid ID
		if ( code_snippets()->get_menu_slug( 'edit' ) === $_REQUEST['page'] ) {
			if ( ! isset( $_REQUEST['id'] ) || 0 == $_REQUEST['id'] ) {
				wp_redirect( code_snippets()->get_menu_url( 'add' ) );
				exit;
			}
		}

		// Load the contextual help tabs
		$contextual_help = new Contextual_Help( 'edit' );
		$contextual_help->load();

		// Register action hooks

		if ( get_setting( 'general', 'enable_description' ) ) {
			add_action( 'code_snippets/admin/single', array( $this, 'render_description_editor' ), 9 );
		}

		if ( get_setting( 'general', 'enable_tags' ) ) {
			add_action( 'code_snippets/admin/single', array( $this, 'render_tags_editor' ) );
		}

		add_action( 'code_snippets/admin/single', array( $this, 'render_priority_setting' ), 0 );

		if ( get_setting( 'general', 'snippet_scope_enabled' ) ) {
			add_action( 'code_snippets/admin/single', array( $this, 'render_scope_setting' ), 1 );
		}

		if ( is_network_admin() ) {
			add_action( 'code_snippets/admin/single', array( $this, 'render_multisite_sharing_setting' ), 1 );
		}

		// Process any submitted actions
		$this->process_actions();

		// Retrieve the current snippet object
		$this->load_snippet_data();
	}

	/**
	 * Load the data for the snippet currently being edited
	 */
	public function load_snippet_data() {
		$edit_id = isset( $_REQUEST['id'] ) && intval( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;

		$snippet = $this->snippet = get_snippet( $edit_id );

		if ( 0 === $edit_id && isset( $_GET['type'] ) && $_GET['type'] !== $snippet->type ) {
			if ( 'php' === $_GET['type'] ) {
				$snippet->scope = 'global';
			} elseif ( 'css' === $_GET['type'] ) {
				$snippet->scope = 'site-css';
			}
		}
	}

	/**
	 * Process data sent from the edit page
	 */
	private function process_actions() {

		/* Check for a valid nonce */
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'save_snippet' ) ) {
			return;
		}

		if ( isset( $_POST['save_snippet'] ) || isset( $_POST['save_snippet_execute'] ) ||
		     isset( $_POST['save_snippet_activate'] ) || isset( $_POST['save_snippet_deactivate'] ) ) {
			$this->save_posted_snippet();
		}

		if ( isset( $_POST['snippet_id'] ) ) {

			/* Delete the snippet if the button was clicked */
			if ( isset( $_POST['delete_snippet'] ) ) {
				delete_snippet( $_POST['snippet_id'] );
				wp_redirect( add_query_arg( 'result', 'delete', code_snippets()->get_menu_url( 'manage' ) ) );
				exit;
			}

			/* Export the snippet if the button was clicked */
			if ( isset( $_POST['export_snippet'] ) ) {
				export_snippets( array( $_POST['snippet_id'] ) );
			}

			/* Download the snippet if the button was clicked */
			if ( isset( $_POST['download_snippet'] ) ) {
				download_snippets( array( $_POST['snippet_id'] ) );
			}
		}
	}

	/**
	 * Remove the sharing status from a network snippet
	 *
	 * @param int $snippet_id
	 */
	private function unshare_network_snippet( $snippet_id ) {
		$shared_snippets = get_site_option( 'shared_network_snippets', array() );

		if ( ! in_array( $snippet_id, $shared_snippets ) ) {
			return;
		}

		/* Remove the snippet ID from the array */
		$shared_snippets = array_diff( $shared_snippets, array( $snippet_id ) );
		update_site_option( 'shared_network_snippets', array_values( $shared_snippets ) );

		/* Deactivate on all sites */
		global $wpdb;
		if ( $sites = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" ) ) {

			foreach ( $sites as $site ) {
				switch_to_blog( $site );
				$active_shared_snippets = get_option( 'active_shared_network_snippets' );

				if ( is_array( $active_shared_snippets ) ) {
					$active_shared_snippets = array_diff( $active_shared_snippets, array( $snippet_id ) );
					update_option( 'active_shared_network_snippets', $active_shared_snippets );
				}
			}

			restore_current_blog();
		}
	}

	private function code_error_callback( $out ) {
		$error = error_get_last();

		if ( is_null( $error ) ) {
			return $out;
		}

		$m = '<h3>' . __( "Don't Panic", 'code-snippets' ) . '</h3>';
		/* translators: %d: line where error was produced */
		$m .= '<p>' . sprintf( __( 'The code snippet you are trying to save produced a fatal error on line %d:', 'code-snippets' ), $error['line'] ) . '</p>';
		$m .= '<strong>' . $error['message'] . '</strong>';
		$m .= '<p>' . __( 'The previous version of the snippet is unchanged, and the rest of this site should be functioning normally as before.', 'code-snippets' ) . '</p>';
		$m .= '<p>' . __( 'Please use the back button in your browser to return to the previous page and try to fix the code error.', 'code-snippets' );
		$m .= ' ' . __( 'If you prefer, you can close this page and discard the changes you just made. No changes will be made to this site.', 'code-snippets' ) . '</p>';

		return $m;
	}

	/**
	 * Validate the snippet code before saving to database
	 *
	 * @param Snippet $snippet
	 *
	 * @return bool true if code produces errors
	 */
	private function validate_code( Snippet $snippet ) {

		if ( empty( $snippet->code ) || 'php' !== $snippet->type ) {
			return false;
		}

		ob_start( array( $this, 'code_error_callback' ) );

		$result = eval( $snippet->code );

		ob_end_clean();

		do_action( 'code_snippets/after_execute_snippet', $snippet->id, $snippet->code, $result );

		return false === $result;
	}

	/**
	 * Save the posted snippet data to the database and redirect
	 */
	private function save_posted_snippet() {

		/* Build snippet object from fields with 'snippet_' prefix */
		$snippet = new Snippet();

		foreach ( $_POST as $field => $value ) {
			if ( 'snippet_' === substr( $field, 0, 8 ) ) {

				/* Remove the 'snippet_' prefix from field name and set it on the object */
				$snippet->set_field( substr( $field, 8 ), stripslashes( $value ) );
			}
		}

		$was_active = $snippet->active;

		if ( isset( $_POST['save_snippet_execute'] ) && 'single-use' !== $snippet->scope ) {
			unset( $_POST['save_snippet_execute'] );
			$_POST['save_snippet'] = 'yes';
		}

		/* Activate or deactivate the snippet before saving if we clicked the button */

		if ( isset( $_POST['save_snippet_execute'] ) ) {
			$snippet->active = 1;
		} elseif ( isset( $_POST['snippet_sharing'] ) && 'on' === $_POST['snippet_sharing'] ) {
			// Shared network snippets cannot be network activated
			$snippet->active = 0;
			unset( $_POST['save_snippet_activate'], $_POST['save_snippet_deactivate'] );
		} elseif ( isset( $_POST['save_snippet_activate'] ) ) {
			$snippet->active = 1;
		} elseif ( isset( $_POST['save_snippet_deactivate'] ) ) {
			$snippet->active = 0;
		}

		/* Deactivate snippet if code contains errors */
		if ( 'php' === $snippet->type && $snippet->active && 'single-use' !== $snippet->scope ) {
			if ( $code_error = $this->validate_code( $snippet ) ) {
				$snippet->active = 0;
			}
		}

		/* Save the snippet to the database */
		$snippet_id = save_snippet( $snippet );

		/* Update the shared network snippets if necessary */
		if ( $snippet_id && is_network_admin() ) {

			if ( isset( $_POST['snippet_sharing'] ) && 'on' === $_POST['snippet_sharing'] ) {
				$shared_snippets = get_site_option( 'shared_network_snippets', array() );

				/* Add the snippet ID to the array if it isn't already */
				if ( ! in_array( $snippet_id, $shared_snippets ) ) {
					$shared_snippets[] = $snippet_id;
					update_site_option( 'shared_network_snippets', array_values( $shared_snippets ) );
				}
			} else {
				$this->unshare_network_snippet( $snippet_id );
			}
		}

		/* Bump CSS file version where necessary */
		$previous_scope = isset( $_POST['current_snippet_scope'] ) ? $_POST['current_snippet_scope'] : '';
		if ( ( $snippet->active || $was_active ) && 'css' === $snippet->type ) {

			foreach ( array( 'admin-css', 'site-css' ) as $scope ) {
				if ( $scope === $snippet->scope || $scope === $previous_scope ) {
					code_snippets()->style_loader->increment_rev( $scope, is_network_admin() );
				}
			}
		}

		/* If the saved snippet ID is invalid, display an error message */
		if ( ! $snippet_id || $snippet_id < 1 ) {
			/* An error occurred */
			wp_redirect( add_query_arg( 'result', 'save-error', code_snippets()->get_menu_url( 'add' ) ) );
			exit;
		}

		/* Display message if a parse error occurred */
		if ( isset( $code_error ) && $code_error ) {
			wp_redirect( add_query_arg(
				array( 'id' => $snippet_id, 'result' => 'code-error' ),
				code_snippets()->get_menu_url( 'edit' )
			) );
			exit;
		}

		/* Set the result depending on if the snippet was just added */
		$result = isset( $_POST['snippet_id'] ) ? 'updated' : 'added';

		/* Append a suffix if the snippet was activated or deactivated */
		if ( isset( $_POST['save_snippet_activate'] ) ) {
			$result .= '-and-activated';
		} elseif ( isset( $_POST['save_snippet_deactivate'] ) ) {
			$result .= '-and-deactivated';
		} elseif ( isset( $_POST['save_snippet_execute'] ) ) {
			$result .= '-and-executed';
		}

		/* Redirect to edit snippet page */
		$redirect_uri = add_query_arg(
			array( 'id' => $snippet_id, 'result' => $result ),
			code_snippets()->get_menu_url( 'edit' )
		);

		wp_redirect( esc_url_raw( $redirect_uri ) );
		exit;
	}

	/**
	 * Add a description editor to the single snippet page
	 *
	 * @param Snippet $snippet The snippet being used for this page
	 */
	function render_description_editor( Snippet $snippet ) {
		$settings = Settings\get_settings_values();
		$settings = $settings['description_editor'];
		$heading = __( 'Description', 'code-snippets' );

		/* Hack to remove space between heading and editor tabs */
		if ( ! $settings['media_buttons'] && 'false' !== get_user_option( 'rich_editing' ) ) {
			$heading = "<div>$heading</div>";
		}

		echo '<h2><label for="snippet_description">', $heading, '</label></h2>';

		remove_editor_styles(); // stop custom theme styling interfering with the editor

		wp_editor(
			$snippet->desc,
			'description',
			apply_filters( 'code_snippets/admin/description_editor_settings', array(
				'textarea_name' => 'snippet_description',
				'textarea_rows' => $settings['rows'],
				'teeny'         => ! $settings['use_full_mce'],
				'media_buttons' => $settings['media_buttons'],
			) )
		);
	}

	/**
	 * Render the interface for editing snippet tags
	 *
	 * @param Snippet $snippet the snippet currently being edited
	 */
	function render_tags_editor( Snippet $snippet ) {

		?>
		<h2 style="margin: 25px 0 10px;">
			<label for="snippet_tags" style="cursor: auto;">
				<?php esc_html_e( 'Tags', 'code-snippets' ); ?>
			</label>
		</h2>

		<input type="text" id="snippet_tags" name="snippet_tags" style="width: 100%;"
		       placeholder="<?php esc_html_e( 'Enter a list of tags; separated by commas', 'code-snippets' ); ?>"
		       value="<?php echo esc_attr( $snippet->tags_list ); ?>" />
		<?php
	}

	/**
	 * Render the snippet priority setting
	 *
	 * @param Snippet $snippet the snippet currently being edited
	 */
	public function render_priority_setting( Snippet $snippet ) {
		?>
		<p class="snippet-priority"
		   title="<?php esc_attr_e( 'Snippets with a lower priority number will run before those with a higher number.', 'code-snippets' ); ?>">
			<label for="snippet_priority"><?php esc_html_e( 'Priority', 'code-snippets' ); ?></label>

			<input name="snippet_priority" type="number" id="snippet_priority" value="<?php echo intval( $snippet->priority ); ?>">
		</p>
		<?php
	}

	/**
	 * Render the snippet scope setting
	 *
	 * @param Snippet $snippet the snippet currently being edited
	 */
	function render_scope_setting( Snippet $snippet ) {

		$icons = Snippet::get_scope_icons();

		echo '<h2 class="screen-reader-text">' . esc_html__( 'Scope', 'code-snippets' ) . '</h2>';

		$all_scopes = array(
			'php' => array(
				'global'     => __( 'Run snippet everywhere', 'code-snippets' ),
				'admin'      => __( 'Only run in administration area', 'code-snippets' ),
				'front-end'  => __( 'Only run on site front-end', 'code-snippets' ),
				'single-use' => __( 'Only run once', 'code-snippets' ),
			),
			'css' => array(
				'site-css'  => __( 'Site front-end styles', 'code-snippets' ),
				'admin-css' => __( 'Administration area styles', 'code-snippets' ),
			),
		);

		if ( 0 !== $snippet->id ) {
			$all_scopes = array_intersect_key( $all_scopes, array( $snippet->type => 1 ) );
		}

		foreach ( $all_scopes as $type => $scopes ) {
			printf( '<p class="snippet-scope %s-scopes-list">', $type );

			foreach ( $scopes as $scope => $label ) {
				printf( '<label><input type="radio" name="snippet_scope" value="%s"', $scope );
				checked( $scope, $snippet->scope );
				printf( '> <span class="dashicons dashicons-%s"></span> %s</label>', $icons[ $scope ], esc_html( $label ) );
			}

			echo '</p>';
		}
	}

	/**
	 * Render the setting for shared network snippets
	 *
	 * @param object $snippet The snippet currently being edited
	 */
	function render_multisite_sharing_setting( $snippet ) {
		$shared_snippets = get_site_option( 'shared_network_snippets', array() );
		?>

		<div class="snippet-sharing-setting">
			<h2 class="screen-reader-text"><?php _e( 'Sharing Settings', 'code-snippets' ); ?></h2>
			<label for="snippet_sharing">
				<input type="checkbox" name="snippet_sharing"
					<?php checked( in_array( $snippet->id, $shared_snippets ) ); ?>>
				<?php esc_html_e( 'Allow this snippet to be activated on individual sites on the network', 'code-snippets' ); ?>
			</label>
		</div>

		<?php
	}

	/**
	 * Retrieve the first error in a snippet's code
	 *
	 * @param $snippet_id
	 *
	 * @return array|bool
	 */
	private function get_snippet_error( $snippet_id ) {

		if ( ! intval( $snippet_id ) ) {
			return false;
		}

		$snippet = get_snippet( intval( $snippet_id ) );

		if ( '' === $snippet->code ) {
			return false;
		}

		ob_start();
		$result = eval( $snippet->code );
		ob_end_clean();

		if ( false !== $result ) {
			return false;
		}

		$error = error_get_last();

		if ( is_null( $error ) ) {
			return false;
		}

		return $error;
	}

	/**
	 * Print the status and error messages
	 */
	protected function print_messages() {

		if ( ! isset( $_REQUEST['result'] ) ) {
			return;
		}

		$result = $_REQUEST['result'];

		if ( 'code-error' === $result ) {

			if ( isset( $_REQUEST['id'] ) && $error = $this->get_snippet_error( $_REQUEST['id'] ) ) {

				printf(
					'<div id="message" class="error fade"><p>%s</p><p><strong>%s</strong></p></div>',
					/* translators: %d: line of file where error originated */
					sprintf( __( 'The snippet has been deactivated due to an error on line %d:', 'code-snippets' ), $error['line'] ),
					$error['message']
				);

			} else {
				echo '<div id="message" class="error fade"><p>', __( 'The snippet has been deactivated due to an error in the code.', 'code-snippets' ), '</p></div>';
			}

			return;
		}

		if ( 'save-error' === $result ) {
			echo '<div id="message" class="error fade"><p>', __( 'An error occurred when saving the snippet.', 'code-snippets' ), '</p></div>';

			return;
		}

		$messages = array(
			'added'                   => __( 'Snippet <strong>added</strong>.', 'code-snippets' ),
			'updated'                 => __( 'Snippet <strong>updated</strong>.', 'code-snippets' ),
			'added-and-activated'     => __( 'Snippet <strong>added</strong> and <strong>activated</strong>.', 'code-snippets' ),
			'updated-and-executed'    => __( 'Snippet <strong>added</strong> and <strong>executed</strong>.', 'code-snippets' ),
			'updated-and-activated'   => __( 'Snippet <strong>updated</strong> and <strong>activated</strong>.', 'code-snippets' ),
			'updated-and-deactivated' => __( 'Snippet <strong>updated</strong> and <strong>deactivated</strong>.', 'code-snippets' ),
		);

		if ( isset( $messages[ $result ] ) ) {
			echo '<div id="message" class="updated fade"><p>', $messages[ $result ], '</p></div>';
		}
	}

	/**
	 * Enqueue assets for the edit menu
	 */
	public function enqueue_assets() {
		$plugin = code_snippets();
		$rtl = is_rtl() ? '-rtl' : '';

		enqueue_code_editor_assets();

		wp_enqueue_style(
			'code-snippets-edit',
			plugins_url( "css/min/edit{$rtl}.css", $plugin->file ),
			array(), $plugin->version
		);

		wp_enqueue_script(
			'code-snippets-edit-menu',
			plugins_url( 'js/min/edit.js', $plugin->file ),
			array(), $plugin->version, true
		);

		$atts = get_code_editor_atts( $this->snippet->type, array(), true );
		$inline_script = 'var code_snippets_editor_atts = ' . $atts . ';';

		wp_add_inline_script( 'code-snippets-edit-menu', $inline_script, 'before' );

		if ( get_setting( 'general', 'enable_tags' ) ) {

			wp_enqueue_script(
				'code-snippets-edit-menu-tags',
				plugins_url( 'js/min/edit-tags.js', $plugin->file ),
				array(
					'jquery', 'jquery-ui-core',
					'jquery-ui-widget', 'jquery-ui-position', 'jquery-ui-autocomplete',
					'jquery-effects-blind', 'jquery-effects-highlight',
				),
				$plugin->version, true
			);

			$snippet_tags = wp_json_encode( get_all_snippet_tags() );
			$inline_script = 'var code_snippets_all_tags = ' . $snippet_tags . ';';

			wp_add_inline_script( 'code-snippets-edit-menu-tags', $inline_script, 'before' );
		}
	}

	/**
	 * Remove the old CodeMirror version used by the Debug Bar Console plugin
	 * that is messing up the snippet editor
	 */
	function remove_debug_bar_codemirror() {

		/* Try to discern if we are on the single snippet page as best as we can at this early time */
		if ( ! is_admin() || 'admin.php' !== $GLOBALS['pagenow'] ) {
			return;
		}

		if ( ! isset( $_GET['page'] ) || code_snippets()->get_menu_slug( 'edit' ) !== $_GET['page'] && code_snippets()->get_menu_slug( 'settings' ) !== $_GET['page'] ) {
			return;
		}

		remove_action( 'debug_bar_enqueue_scripts', 'debug_bar_console_scripts' );
	}

	/**
	 * Retrieve a list of submit actions for a given snippet
	 *
	 * @param Snippet $snippet
	 * @param bool    $extra_actions
	 *
	 * @return array
	 */
	public function get_actions_list( $snippet, $extra_actions = true ) {
		$actions = array(
			'save_snippet' => __( 'Save Changes', 'code-snippets' ),
		);

		if ( 'single-use' === $snippet->scope ) {
			$actions['save_snippet_execute'] = __( 'Save Changes and Execute Once', 'code-snippets' );

		} elseif ( ! $snippet->shared_network || ! is_network_admin() ) {

			if ( $snippet->active ) {
				$actions['save_snippet_deactivate'] = __( 'Save Changes and Deactivate', 'code-snippets' );
			} else {
				$actions['save_snippet_activate'] = __( 'Save Changes and Activate', 'code-snippets' );
			}
		}

		// Make the 'Save and Activate' button the default if the setting is enabled
		if ( ! $snippet->active && 'single-use' !== $snippet->scope &&
		     get_setting( 'general', 'activate_by_default' ) ) {
			$actions = array_reverse( $actions );
		}

		if ( $extra_actions && 0 !== $snippet->id ) {

			if ( apply_filters( 'code_snippets/enable_downloads', true ) ) {
				$actions['download_snippet'] = __( 'Download', 'code-snippets' );
			}

			$actions['export_snippet'] = __( 'Export', 'code-snippets' );
			$actions['delete_snippet'] = __( 'Delete', 'code-snippets' );
		}

		return $actions;
	}

	/**
	 * Render the submit buttons for a code snippet
	 *
	 * @param Snippet $snippet
	 * @param string  $size
	 * @param bool    $extra_actions
	 */
	public function render_submit_buttons( $snippet, $size = '', $extra_actions = true ) {

		$actions = $this->get_actions_list( $snippet, $extra_actions );
		$type = 'primary';
		$size = $size ? ' ' . $size : '';

		foreach ( $actions as $action => $label ) {
			$other = null;

			if ( 'delete_snippet' === $action ) {

				$other = sprintf( 'onclick="%s"', esc_js(
					sprintf(
						'return confirm("%s");',
						__( 'You are about to permanently delete this snippet.', 'code-snippets' ) . "\n" .
						__( "'Cancel' to stop, 'OK' to delete.", 'code-snippets' )
					)
				) );
			}

			submit_button( $label, $type . $size, $action, false, $other );

			if ( 'primary' === $type ) {
				$type = 'secondary';
			}
		}
	}
}
